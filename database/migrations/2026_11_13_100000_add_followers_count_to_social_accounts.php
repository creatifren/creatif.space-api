<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The account's follower count as the relay last reported it, and when we
 * last asked. Null count = the network does not report one (or not yet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('followers_count')->nullable();
            $table->timestamp('followers_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn(['followers_count', 'followers_synced_at']);
        });
    }
};
