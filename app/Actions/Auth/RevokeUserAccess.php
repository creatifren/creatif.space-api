<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every way into an account, closed: API keys (Sanctum), OAuth access and
 * refresh tokens and auth codes (Passport), OAuth clients the user owns,
 * and web sessions (database session driver). None of these tables
 * cascades from a soft delete, and the token tables have no foreign key.
 *
 * Used when the account is deleted (User::deleting) and when deletion is
 * requested — the person can sign in again during the grace period.
 */
final class RevokeUserAccess
{
    public static function run(User $user): void
    {
        $user->tokens()->delete();

        $accessTokens = DB::table('oauth_access_tokens')->where('user_id', $user->id)->pluck('id');
        DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $accessTokens)->delete();
        DB::table('oauth_access_tokens')->whereIn('id', $accessTokens)->delete();
        DB::table('oauth_auth_codes')->where('user_id', $user->id)->delete();
        DB::table('oauth_clients')
            ->whereIn('owner_type', [User::class, $user->getMorphClass()])
            ->where('owner_id', $user->id)
            ->delete();

        // Web sessions (SESSION_DRIVER=database in production).
        $sessions = (string) config('session.table', 'sessions');
        if (Schema::hasTable($sessions)) {
            DB::table($sessions)->where('user_id', $user->id)->delete();
        }
    }
}
