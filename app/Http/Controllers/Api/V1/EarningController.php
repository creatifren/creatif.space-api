<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WalletTransactionType;
use App\Enums\WithdrawalStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\WithdrawalResource;
use App\Models\User;
use App\Models\Withdrawal;
use App\Support\Wallet;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** USD payouts, sent manually by staff through PayPal. Credits never pay out. */
class EarningController extends Controller
{
    public function showPayoutAccount(Request $request): JsonResponse
    {
        Workspace::ownerOnly($request->user());

        return response()->json(['data' => self::payoutAccount($request->user())]);
    }

    public function savePayoutAccount(Request $request): JsonResponse
    {
        Workspace::ownerOnly($request->user());
        $validated = $request->validate(['paypal_email' => ['required', 'email:rfc', 'max:255']]);
        $user = $request->user();
        $user->forceFill(['payout_paypal_email' => $validated['paypal_email']])->save();

        return response()->json(['data' => self::payoutAccount($user)]);
    }

    private static function payoutAccount(User $user): ?array
    {
        $email = $user->getAttributes()['payout_paypal_email'] ?? null;

        return $email === null ? null : ['paypal_email' => $email];
    }

    public function withdrawals(Request $request): AnonymousResourceCollection
    {
        Workspace::ownerOnly($request->user());

        return WithdrawalResource::collection($request->user()->withdrawals()->latest('id')->limit(50)->get());
    }

    public function withdraw(Request $request): JsonResponse
    {
        Workspace::ownerOnly($request->user());
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:'.Withdrawal::MINIMUM],
            'paypal_email' => ['sometimes', 'email:rfc', 'max:255'],
            'wallet' => ['sometimes', 'string', 'in:main,affiliate'],
        ]);
        $user = $request->user();
        $wallet = $validated['wallet'] ?? Wallet::MAIN;
        $email = $validated['paypal_email'] ?? $user->getAttributes()['payout_paypal_email'] ?? null;
        if ($email === null) {
            throw ValidationException::withMessages(['paypal_email' => 'No PayPal email saved — add one first.']);
        }

        $withdrawal = DB::transaction(function () use ($user, $validated, $wallet, $email) {
            // Serialize requests, including the open-request check. Checking
            // outside this lock would let two concurrent requests both pass.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($user->withdrawals()->where('wallet', $wallet)
                ->whereIn('status', [WithdrawalStatus::Pending, WithdrawalStatus::Processing])->exists()) {
                throw ValidationException::withMessages(['amount' => 'A withdrawal is already on its way — wait for it to land.']);
            }
            if ($validated['amount'] > Wallet::balance($user, $wallet)) {
                throw ValidationException::withMessages(['amount' => 'That’s more than your balance.']);
            }
            $withdrawal = $user->withdrawals()->create([
                'wallet' => $wallet, 'amount' => $validated['amount'], 'currency' => 'USD',
                'paypal_email' => $email, 'status' => WithdrawalStatus::Pending,
            ]);
            Wallet::debit($user, WalletTransactionType::WithdrawalDebit, $withdrawal->amount,
                'withdrawal', $withdrawal->id, wallet: $wallet);

            return $withdrawal;
        });

        return (new WithdrawalResource($withdrawal))->response()->setStatusCode(201);
    }
}
