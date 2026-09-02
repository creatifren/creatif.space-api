<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * approval.cancelled and digest.weekly were removed from
     * NotificationType (no notification ever raised them). Stale rows
     * would throw a ValueError when the model casts `type` to the enum.
     */
    public function up(): void
    {
        DB::table('notification_preferences')
            ->whereIn('type', ['approval.cancelled', 'digest.weekly'])
            ->delete();
    }

    public function down(): void
    {
        // No-op: deleted preference rows can't be restored; the
        // default-on behaviour covers users either way.
    }
};
