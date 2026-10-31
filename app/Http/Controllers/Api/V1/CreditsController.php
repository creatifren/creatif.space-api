<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CreditPurchase;
use App\Models\SocialPost;
use App\Models\WalletTransaction;
use App\Services\CreemService;
use App\Support\Credits;
use App\Support\PlanQuota;
use App\Support\Wallet;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /credits — the balance, what is already committed, storage
 * with its rented blocks, and every movement. Owner-only for anything that
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
            'balance' => Credits::balance($user),
            'committed' => Credits::committed($user),
            'rate' => [
                'per_dollar' => Credits::PER_DOLLAR,
                'per_send' => Credits::PER_SEND,
                'block_gb' => Credits::BLOCK_GB,
                'per_block' => Credits::PER_BLOCK,
                'per_block_bulk' => Credits::PER_BLOCK_BULK,
                'bulk_from_gb' => Credits::BULK_FROM_GB,
            ],
            // Publishes the plan includes this period — used before Credits.
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
                'blocks' => Credits::blocks(Workspace::owner($user)),
                'rent' => Credits::rent($user),
                'read_only' => PlanQuota::readOnly($user),
            ],
            'packs' => self::PACKS,
            'top_up' => ['min' => Credits::TOP_UP_MIN, 'max' => Credits::TOP_UP_MAX],
        ]]);
    }

    /**
     * Every movement, newest first. Sends name the post they paid for.
     */
    public function ledger(Request $request): JsonResponse
    {
        $owner = Workspace::owner($request->user());

        $rows = WalletTransaction::query()
            ->where('user_id', $owner->id)
            ->where('wallet', Wallet::CREDITS)
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

        return response()->json(['data' => [
            'checkout_url' => $checkout['checkout_url'],
            'purchase_id' => $purchase->ulid,
        ]], 201);
    }

    /**
     * Rent more storage now — the first month is taken today.
     */
    public function addBlocks(Request $request): JsonResponse
    {
        Workspace::ownerOnly($request->user());
        $validated = $request->validate(['blocks' => ['required', 'integer', 'min:1', 'max:200']]);

        Credits::addBlocks($request->user(), $validated['blocks']);

        return $this->show($request);
    }

    /**
     * Stop renting blocks from next month. Nothing comes back for this one,
     * and blocks the files still need are refused — read-only is what a
     * lapse does, not something to choose.
     */
    public function releaseBlocks(Request $request): JsonResponse
    {
        Workspace::ownerOnly($request->user());
        $validated = $request->validate(['blocks' => ['required', 'integer', 'min:1']]);
        $user = $request->user();

        $limit = PlanQuota::storageLimit($user);
        $after = $limit === null ? null : $limit - $validated['blocks'] * Credits::BLOCK_GB * 1024 ** 3;
        if ($after !== null && PlanQuota::storageUsed($user) > $after) {
            return response()->json([
                'message' => 'Your files would not fit without those blocks — free some space first.',
            ], 422);
        }

        Credits::releaseBlocks($user, $validated['blocks']);

        return $this->show($request);
    }
}
