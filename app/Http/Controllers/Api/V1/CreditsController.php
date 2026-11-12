<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\NotEnoughCredits;
use App\Exceptions\PlanFeatureRequired;
use App\Http\Controllers\Controller;
use App\Http\Middleware\CapTokenSends;
use App\Models\CreditPurchase;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\CreemService;
use App\Support\AiCaptionReady;
use App\Support\Credits;
use App\Support\Media\MediaQuota;
use App\Support\PlanQuota;
use App\Support\ProductEvents;
use App\Support\Wallet;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /credits — the balance, what is already committed, storage
 * (no longer rentable: Media v2), and every movement. Owner-only for anything that
 * spends; members can read.
 */
class CreditsController extends Controller
{
    /** Top-up shortcuts, in whole dollars; any whole amount between
        Credits::TOP_UP_MIN and TOP_UP_MAX is accepted too (custom). */
    private const PACKS = [10, 20, 50, 100];

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            // Whose plan the wallet hangs off — a teammate sees the owner's.
            'plan' => Workspace::owner($user)->plan()->key,
            // Top-up Credits, as before; the rest is the Monthly bucket.
            'balance' => Credits::balance($user),
            'monthly_balance' => Credits::monthlyBalance($user),
            'monthly_resets_at' => Credits::cycleEnds(Workspace::owner($user))->toIso8601String(),
            'monthly_amount' => Credits::monthlyAmount(Workspace::owner($user)),
            'topup_balance' => Credits::balance($user),
            'total' => Credits::total($user),
            'committed' => Credits::committed($user),
            'rate' => [
                'per_dollar' => Credits::PER_DOLLAR,
                'per_send' => Credits::perSend(),
                'per_send_by_platform' => Credits::perSendByPlatform(),
            ],
            // Legacy: the old count-based allowance, no longer charged.
            'allowance' => [
                'limit' => PlanQuota::socialPostsLimit($user),
                'used' => Credits::allowanceUsed($user),
                'resets_at' => Credits::allowanceSince(Workspace::owner($user))
                    ->addMonthNoOverflow()->toIso8601String(),
            ],
            'storage' => [
                'used' => PlanQuota::storageUsed($user),
                'plan' => PlanQuota::planStorageLimit($user),
                'limit' => PlanQuota::storageLimit($user),
                'read_only' => PlanQuota::readOnly($user),
                // Media v2: Free {used_bytes, quota_bytes}; paid {keep_used_bytes, keep_quota_bytes}.
                'media' => MediaQuota::usage($user),
            ],
            'packs' => self::PACKS,
            'top_up' => ['min' => Credits::TOP_UP_MIN, 'max' => Credits::TOP_UP_MAX],
            'limits' => self::limits($user),
        ]]);
    }

    /**
     * The plan's limits and what is used of them, all read from the plans
     * table (no number lives here) — the MCP server's get_credits answer.
     *
     * @return array<string, mixed>
     */
    public static function limits(User $user): array
    {
        $owner = Workspace::owner($user);
        $plan = $owner->plan();
        $accountsLimit = $plan->quota('social_accounts');

        return [
            'plan' => ['key' => $plan->key->value, 'name' => $plan->name],
            // What the plan lists per month. Credits are what a send is
            // actually checked against; this is the plan's reference count.
            'monthly_posts' => [
                'limit' => PlanQuota::socialPostsLimit($user),
                'used' => Credits::sendsThisCycle($user),
                'resets_at' => Credits::cycleEnds($owner)->toIso8601String(),
            ],
            'connected_accounts' => [
                'limit' => $accountsLimit,
                'used' => $owner->socialAccounts()->usable()->count(),
            ],
            // Plans v2: what can join the queue, and for how many clients.
            'scheduling' => [
                'window_days' => PlanQuota::scheduleWindowDays($user),
                'queued_posts' => [
                    'limit' => PlanQuota::queueLimit($user),
                    'used' => PlanQuota::queuedPosts($user),
                ],
            ],
            'approval_clients' => [
                'limit' => PlanQuota::approvalClientsLimit($user),
                'used' => PlanQuota::approvalClients($user)->count(),
            ],
            'features' => [
                'approval_links' => $plan->feature('client_approvals'),
                'analytics' => $plan->feature('full_analytics'),
                'delivery_history' => $plan->feature('delivery_history'),
                'branding_removed' => $plan->feature('branding_removed'),
                // The gates PlanQuota::requireFeature() enforces (402 when false).
                ...collect(PlanQuota::FEATURES)->mapWithKeys(fn (string $f) => [$f => $plan->feature($f)])->all(),
            ],
            // B10: the plan may include AI captions while POST /ai/caption is
            // still switched off (AI_CAPTION_ENABLED) or not set up: then
            // MCP suggest_caption answers not_enabled. True only when it works.
            'ai_caption_ready' => $plan->feature('ai_caption') && AiCaptionReady::check(),
            'credits' => [
                'monthly' => Credits::monthlyBalance($user),
                'monthly_amount' => Credits::monthlyAmount($owner),
                'topup' => Credits::balance($user),
                'total' => Credits::total($user),
                'resets_at' => Credits::cycleEnds($owner)->toIso8601String(),
            ],
            // This cycle's Credits by what they paid for (get_credits).
            'spend' => [
                'since' => Credits::cycleSince($owner)->toIso8601String(),
                'by_action' => Credits::spendThisCycle($user),
            ],
            'price_per_send' => [
                'default' => Credits::perSend(),
                'by_platform' => Credits::perSendByPlatform(),
            ],
            'mcp_daily_sends' => CapTokenSends::status($user),
            'urls' => [
                'billing' => PlanFeatureRequired::billingUrl(),
                'top_up' => NotEnoughCredits::topUpUrl(),
            ],
        ];
    }

    /**
     * Every movement, newest first. Sends name the post they paid for.
     */
    public function ledger(Request $request): JsonResponse
    {
        $owner = Workspace::owner($request->user());

        $rows = WalletTransaction::query()
            ->where('user_id', $owner->id)
            ->whereIn('wallet', [Wallet::CREDITS, Wallet::MONTHLY])
            ->orderByDesc('id')
            ->cursorPaginate(30);

        $items = collect($rows->items());
        $posts = SocialPost::query()
            ->whereIn('id', $items->where('reference_type', 'social_post')->pluck('reference_id'))
            ->get(['id', 'ulid', 'caption'])
            ->keyBy('id');

        return response()->json([
            'data' => $items->map(fn (WalletTransaction $t) => [
                'id' => $t->ulid,
                'type' => $t->type->value,
                'amount' => $t->amount,
                // balance_after is that bucket's own running balance.
                'bucket' => $t->wallet === Wallet::MONTHLY ? 'monthly' : 'topup',
                'balance_after' => $t->balance_after,
                'note' => $t->note,
                'post' => $t->reference_type === 'social_post' && isset($posts[$t->reference_id])
                    ? ['id' => $posts[$t->reference_id]->ulid, 'caption' => $posts[$t->reference_id]->caption]
                    : null,
                'created_at' => $t->created_at?->toIso8601String(),
            ])->all(),
            'meta' => ['next_cursor' => $rows->nextCursor()?->encode()],
        ]);
    }

    /**
     * Start a top-up: a pending purchase and a hosted Creem checkout. The
     * Credits land when the webhook says paid, never on the redirect back.
     */
    public function topUp(Request $request, CreemService $creem): JsonResponse
    {
        Workspace::ownerOnly($request->user());

        $validated = $request->validate([
            // Whole dollars: Creem sells the $1 Credits product this many times.
            'dollars' => ['required', 'integer', 'min:'.Credits::TOP_UP_MIN, 'max:'.Credits::TOP_UP_MAX],
        ]);

        $purchase = CreditPurchase::create([
            'user_id' => $request->user()->id,
            'units' => $validated['dollars'],
            'credits' => $validated['dollars'] * Credits::PER_DOLLAR,
            'amount' => $validated['dollars'] * 100,
            'currency' => 'USD',
            'status' => 'pending',
        ]);

        $checkout = $creem->creditsCheckout($purchase, $request->user());
        $purchase->forceFill(['creem_checkout_id' => $checkout['id']])->save();
        ProductEvents::checkoutStarted($request->user(), 'credits', null, null, (int) $purchase->amount);

        return response()->json(['data' => [
            'checkout_url' => $checkout['checkout_url'],
            'purchase_id' => $purchase->ulid,
        ]], 201);
    }
}
