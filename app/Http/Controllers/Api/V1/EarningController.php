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
            ],
        ]);
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
            'bank_code' => ['required', 'string', 'max:20'],
            'account_number' => ['required', 'string', 'max:40'],
            'account_name' => ['required', 'string', 'max:255'],
            // Sales and affiliate commission are separate balances, so they
            // are separate payouts — one cannot be spent out of the other.
            'wallet' => ['sometimes', 'string', 'in:main,affiliate'],
        ]);

        $user = $request->user();
        $wallet = $validated['wallet'] ?? Wallet::MAIN;

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
