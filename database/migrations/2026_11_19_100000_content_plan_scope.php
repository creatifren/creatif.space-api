<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content Plan (Meeting #02): an approval link can be a scope — a date
 * range (in the owner's timezone) and optionally some channels — instead of
 * a fixed list of posts. Every workspace post inside the scope shows,
 * including ones added later. Null scope = the old fixed-list link.
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_approval_links', function (Blueprint $table) {
            // The owner's calendar dates, as chosen, and the zone they mean.
            $table->date('scope_from')->nullable()->after('approver_bound_at');
            $table->date('scope_to')->nullable()->after('scope_from');
            $table->string('scope_timezone', 64)->nullable()->after('scope_to');
            // The same range as UTC instants: [starts, ends) — what queries use.
            $table->timestamp('scope_starts_at')->nullable()->after('scope_timezone');
            $table->timestamp('scope_ends_at')->nullable()->after('scope_starts_at');
            // Social account ids (internal); null = every channel.
            $table->json('scope_account_ids')->nullable()->after('scope_ends_at');
        });

        Schema::table('social_posts', function (Blueprint $table) {
            // A draft the owner wants the client to see on a Content Plan.
            $table->timestamp('review_requested_at')->nullable()->after('approval_at');
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropColumn('review_requested_at');
        });
        Schema::table('social_approval_links', function (Blueprint $table) {
            $table->dropColumn(['scope_from', 'scope_to', 'scope_timezone', 'scope_starts_at', 'scope_ends_at', 'scope_account_ids']);
        });
    }
};
