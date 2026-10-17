<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Space links never expire (UX revise RF-017). The editor no longer offers
 * an expiry and the API no longer accepts one, so any date already set is
 * cleared — otherwise an old link would still die on a day nobody can see
 * or change any more. The column stays: dropping it buys nothing and the
 * public gate reads it harmlessly as "never".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('spaces')->whereNotNull('expires_at')->update(['expires_at' => null]);
    }

    public function down(): void
    {
        // Cleared dates are not recoverable; nothing to restore.
    }
};
