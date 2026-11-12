<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Content Plan calendar feed (ICS). Its URL is derived — the link's
 * ulid plus an HMAC of it and calendar_version under the app key — so no
 * token is stored. Turning the feed off sets calendar_disabled_at and bumps
 * the version, so the old URL never works again. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_approval_links', function (Blueprint $table) {
            $table->unsignedInteger('calendar_version')->default(1)->after('reminded_at');
            $table->timestamp('calendar_disabled_at')->nullable()->after('calendar_version');
        });
    }

    public function down(): void
    {
        Schema::table('social_approval_links', function (Blueprint $table) {
            $table->dropColumn(['calendar_version', 'calendar_disabled_at']);
        });
    }
};
