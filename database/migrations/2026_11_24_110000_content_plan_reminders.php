<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content Plan reminders: the approver gets one email 24 hours before the
 * first undecided post goes out. reminders_enabled is the per-link opt-out
 * (owner PATCH, or the approver's one-click link in the email);
 * reminded_at makes it once. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_approval_links', function (Blueprint $table) {
            $table->boolean('reminders_enabled')->default(true)->after('scope_account_ids');
            $table->timestamp('reminded_at')->nullable()->after('reminders_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('social_approval_links', function (Blueprint $table) {
            $table->dropColumn(['reminders_enabled', 'reminded_at']);
        });
    }
};
