<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Channel health (ChannelHealth): the last live read-only check through
 * the relay — ok / failed, why, and when — and when the owner was emailed
 * about the failure (once per failure; a passing check clears it).
 *
 * removed_at: a disconnected channel the owner removed from Channels. The
 * row stays (published posts and their numbers point at it), hidden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('health_status', 16)->nullable();
            $table->string('health_reason', 32)->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('health_notified_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->index(['status', 'last_verified_at']);
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropIndex(['status', 'last_verified_at']);
            $table->dropColumn(['health_status', 'health_reason', 'last_verified_at', 'health_notified_at', 'removed_at']);
        });
    }
};
