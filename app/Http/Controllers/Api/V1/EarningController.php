<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\WalletTransactionType;
use App\Enums\WithdrawalStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\WithdrawalResource;
use App\Models\Withdrawal;
use App\Support\Wallet;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Insights → Orders → Earnings. The creator's side of the till: what came
 * in, what the platform took, and what can be withdrawn.
 */
class EarningController extends Controller
{
    /**
     * The summary strip. Every figure is derived — nothing here is a stored
     * counter that could drift.
     */
    public function summary(Request $request): JsonResponse
    {
        Workspace::ownerOnly($request->user());
        $user = $request->user();

        $paid = $user->orders()->where('status', OrderStatus::Paid);

        $monthStart = now()->startOfMonth();
        $thisMonth = (clone $paid)->where('paid_at', '>=', $monthStart);

        return response()->json([
            'data' => [
                'balance' => Wallet::balance($user),
                // Sold but not settled: money that exists but can't be
                // withdrawn yet.
                'pending' => (int) $user->orders()
                    ->where('status', OrderStatus::Pending)
                    ->sum('net_amount'),
                'month_gross' => (int) (clone $thisMonth)->sum('amount'),
                'month_fee' => (int) (clone $thisMonth)->sum('fee_amount'),
                'month_orders' => (clone $thisMonth)->count(),
                'lifetime_net' => (int) (clone $paid)->sum('net_amount'),
                'fee_percent' => (float) $user->plan()->fee_percent,
                'plan' => $user->plan()->key,
                'minimum_withdrawal' => Withdrawal::MINIMUM,
                'payout_account' => self::payoutAccount($user),
            ],
        ]);
    }

    /**
     * Save the default payout destination without withdrawing anything.
     * The withdraw form starts from this; each payout still copies the
     * details onto its own row.
     */
    public function savePayoutAccount(Request $request): JsonResponse
    {
        Workspace::ownerOnly($request->user());
        $validated = $request->validate([
            'bank_code' => ['required', 'string', 'max:20'],
            'account_number' => ['required', 'string', 'max:40'],
            'account_name' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $user->forceFill([
            'payout_bank_code' => $validated['bank_code'],
            'payout_account_number' => $validated['account_number'],
            'payout_account_name' => $validated['account_name'],
        ])->save();

        return response()->json(['data' => self::payoutAccount($user)]);
    }

    /**
     * The saved destination, account number masked like withdrawal history —
     * only the withdraw form ever needs the full number, and the owner types
     * it there.
     *
     * @return array{bank_code: string, account_masked: string, account_name: string}|null
     */
    private static function payoutAccount(\App\Models\User $user): ?array
    {
        /* getAttributes(), not property access: a User instance that was
           never re-read since creation (actingAs in tests) has no key for
           these columns, and strict mode turns that into a 500. Missing and
           null both mean "nothing saved". */
        $attrs = $user->getAttributes();

        if (empty($attrs['payout_bank_code'])) {
            return null;
        }

        return [
            'bank_code' => (string) $attrs['payout_bank_code'],
            'account_masked' => '••••'.substr((string) ($attrs['payout_account_number'] ?? ''), -4),
            'account_name' => (string) ($attrs['payout_account_name'] ?? ''),
        ];
    }

    /**
     * Sales, newest first — the creator's own ledger of who bought what.
     */
    public function orders(Request $request): AnonymousResourceCollection
    {
        Workspace::ownerOnly($request->user());

        return OrderResource::collection(
            $request->user()->orders()
                ->with(['offer', 'client', 'space'])
                ->latest('id')
                ->limit(50)
                ->get(),
        );
    }

    public function withdrawals(Request $request): AnonymousResourceCollection
    {
        Workspace::ownerOnly($request->user());

        return WithdrawalResource::collection(
            $request->user()->withdrawals()->latest('id')->limit(50)->get(),
        );
    }

    /**
     * Ask for a payout. The money leaves the balance now; a failed transfer
     * puts it back with its own ledger line rather than deleting this one.
     */
    public function withdraw(Request $request): JsonResponse
    {
        Workspace::ownerOnly($request->user());
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:'.Withdrawal::MINIMUM],
            /* Optional as a trio: omitted means "the saved destination".
               The full number never leaves the server (the client only ever
               sees it masked), so paying out to the saved account has to be
               resolved here, not typed back in the browser. */
            'bank_code' => ['required_with:account_number,account_name', 'string', 'max:20'],
            'account_number' => ['required_with:bank_code,account_name', 'string', 'max:40'],
            'account_name' => ['required_with:bank_code,account_number', 'string', 'max:255'],
            // Sales and affiliate commission are separate balances, so they
            // are separate payouts — one cannot be spent out of the other.
            'wallet' => ['sometimes', 'string', 'in:main,affiliate'],
        ]);

        $user = $request->user();
        $wallet = $validated['wallet'] ?? Wallet::MAIN;

        if (! isset($validated['account_number'])) {
            $saved = $user->getAttributes();

            if (empty($saved['payout_bank_code'])) {
                throw ValidationException::withMessages([
                    'account_number' => 'No destination account saved — add one first.',
                ]);
            }

            $validated['bank_code'] = $saved['payout_bank_code'];
            $validated['account_number'] = $saved['payout_account_number'];
            $validated['account_name'] = $saved['payout_account_name'];
        }

        if ($validated['amount'] > Wallet::balance($user, $wallet)) {
            throw ValidationException::withMessages([
                'amount' => 'That’s more than your balance.',
            ]);
        }

        // One at a time per wallet: a second request while the first is in
        // flight would be paid out of a balance the first already spent.
        // Scoped, or an affiliate payout would block a sales payout.
        $inFlight = $user->withdrawals()
            ->where('wallet', $wallet)
            ->whereIn('status', [WithdrawalStatus::Pending, WithdrawalStatus::Processing])
            ->exists();

        if ($inFlight) {
            throw ValidationException::withMessages([
                'amount' => 'A withdrawal is already on its way — wait for it to land.',
            ]);
        }

        $withdrawal = DB::transaction(function () use ($user, $validated, $wallet) {
            $withdrawal = $user->withdrawals()->create([
                'wallet' => $wallet,
                'amount' => $validated['amount'],
                'bank_code' => $validated['bank_code'],
                'account_number' => $validated['account_number'],
                'account_name' => $validated['account_name'],
                'status' => WithdrawalStatus::Pending,
            ]);

            // Debit inside the same transaction: the ledger check and the
            // row that spends it must not be able to disagree.
            Wallet::debit(
                $user,
                WalletTransactionType::WithdrawalDebit,
                $withdrawal->amount,
                'withdrawal',
                $withdrawal->id,
                wallet: $wallet,
            );

            return $withdrawal;
        });

        return (new WithdrawalResource($withdrawal))->response()->setStatusCode(201);
    }
}
