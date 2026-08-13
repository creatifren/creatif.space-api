<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CommissionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AffiliateResource;
use App\Models\Affiliate;
use App\Models\Commission;
use App\Models\Referral;
use App\Support\ReferralAttribution;
use App\Support\Wallet;
use App\Support\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The affiliate dashboard, and the form that asks to have one.
 *
 * /referral is meant to be invisible until somebody is approved, and that
 * promise is kept by the server answering 404 rather than by the sidebar
 * quietly not linking to it.
 */
class AffiliateController extends Controller
{
    /**
     * Everything /referral renders, in one call.
     */
    public function show(Request $request): JsonResponse
    {
        Workspace::ownerOnly($request->user());
        $user = $request->user();
        $affiliate = $user->affiliate;

        abort_if($affiliate === null, 404);

        $referrals = Referral::query()->where('affiliate_id', $affiliate->id);

        $commissions = Commission::query()
            ->whereIn('referral_id', (clone $referrals)->select('id'));

        return response()->json([
            'data' => [
                'affiliate' => (new AffiliateResource($affiliate))->toArray($request),
                'funnel' => [
                    'clicks' => (clone $referrals)->count(),
                    'signed_up' => (clone $referrals)->whereNotNull('referred_user_id')->count(),
                    'paid' => (clone $referrals)->whereNotNull('first_paid_at')->count(),
                ],
                'balance' => [
                    // Holding money is real but not spendable yet, so it is
                    // reported apart from the balance rather than inside it.
                    'holding' => (int) (clone $commissions)
                        ->where('status', CommissionStatus::Holding)
                        ->sum('amount'),
                    'available' => Wallet::balance($user, Wallet::AFFILIATE),
                ],
                'history' => $this->history($commissions),
            ],
        ]);
    }

    /**
     * Apply. Sign-in is required — a commission has to be payable into a
     * wallet, and a wallet needs an account behind it.
     */
    public function apply(Request $request): JsonResponse
    {
        Workspace::ownerOnly($request->user());
        $validated = $request->validate([
            'link' => ['required', 'string', 'max:255'],
            'audience' => ['required', 'string', 'max:500'],
        ]);

        $user = $request->user();

        // ->exists() rather than the relation property: a lazily-loaded
        // relation can already be cached as null on the model this request
        // is holding, and the unique index would then be the thing that
        // says no — as a 500, not as a sentence.
        if ($user->affiliate()->exists()) {
            throw ValidationException::withMessages([
                'link' => 'You’ve already applied — we’ll email you either way.',
            ]);
        }

        $affiliate = $user->affiliate()->create([
            // Issued now, not at approval, so the approval email can carry
            // the link the person is waiting for.
            'code' => Affiliate::generateCode($user),
            'application' => [
                'name' => $user->name,
                'email' => $user->email,
                'link' => $validated['link'],
                'audience' => $validated['audience'],
            ],
        ]);

        // refresh(): `status` and `tier_percent` are column defaults, and
        // the in-memory model has not seen them yet.
        return (new AffiliateResource($affiliate->refresh()))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Someone opened a referral link. Recorded before any signup, which is
     * what makes the funnel's first number a count rather than a guess.
     */
    public function click(string $code): JsonResponse
    {
        ReferralAttribution::click($code);

        // 204 whatever happened: an unknown code must not tell a stranger
        // which codes exist.
        return response()->json(null, 204);
    }

    /**
     * The commission table on /referral, newest first.
     *
     * @param  Builder<Commission>  $commissions
     * @return list<array<string, mixed>>
     */
    private function history($commissions): array
    {
        return $commissions
            ->with('invoice.subscription.plan')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (Commission $c): array => [
                'id' => $c->id,
                'date' => $c->created_at,
                'plan' => $c->invoice->subscription->plan->name ?? '—',
                'period' => $c->invoice->subscription->billing_period ?? null,
                'tier_percent' => (float) $c->tier_percent,
                'amount' => $c->amount,
                'installment_no' => $c->installment_no,
                'status' => $c->status,
                'hold_until' => $c->hold_until,
            ])
            ->all();
    }
}
