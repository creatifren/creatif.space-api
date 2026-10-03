<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spaces no longer carry approval: a Space is a portfolio and nothing else.
 * Clients go with it — with orders already gone, approving was the only
 * reason anyone ever became one.
 *
 * notification_preferences rows for approval.decided must go: the model
 * casts `type` to NotificationType, and a leftover row would throw once
 * the enum case is gone. Bell rows of the same type go too, so nobody is
 * left with a notification that links to a screen that no longer exists.
 *
 * spaces.settings keeps any stale `approval_mode` key. Nothing reads it,
 * and rewriting every row's JSON to tidy it is not worth the risk.
 */
return new class extends Migration
{
    public function up(): void
    {
        // approval_notes first: it carries the FK to approvals.
        Schema::dropIfExists('approval_notes');
        Schema::dropIfExists('approvals');

        // space_events points at clients, so the column goes before the table.
        Schema::table('space_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('client_id');
        });
        Schema::dropIfExists('clients');

        Schema::table('spaces', function (Blueprint $table): void {
            $table->dropColumn('approval_enabled');
        });

        DB::table('notification_preferences')->where('type', 'approval.decided')->delete();
        // Bell rows store the notification class, not the preference key.
        DB::table('notifications')->where('type', 'App\Notifications\ApprovalDecided')->delete();
    }

    public function down(): void
    {
        // No-op: the dropped tables carried real decisions by real clients
        // that this migration cannot invent back.
    }
};
