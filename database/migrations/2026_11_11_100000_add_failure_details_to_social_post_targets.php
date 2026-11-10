<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a destination failed, kept two ways: `fail_code` (our specific
 * reason code) next to the person-readable `fail_reason`, and the
 * provider's raw text in `provider_error` — server-side only, never in an
 * API response or an analytics event.
 *
 * A rate-limited destination is retried with backoff instead of failing:
 * `attempts`, `retry_at`, and the provider post that carries the retry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_post_targets', function (Blueprint $table) {
            $table->string('fail_code', 32)->nullable()->after('fail_reason');
            $table->text('provider_error')->nullable()->after('fail_code');
            $table->unsignedTinyInteger('attempts')->default(0)->after('provider_error');
            $table->timestamp('retry_at')->nullable()->after('attempts');
            $table->string('retry_provider_post_id', 100)->nullable()->after('retry_at');

            $table->index('retry_at', 'spt_retry_at_idx');
            $table->index('retry_provider_post_id', 'spt_retry_post_idx');
        });
    }

    public function down(): void
    {
        Schema::table('social_post_targets', function (Blueprint $table) {
            $table->dropIndex('spt_retry_at_idx');
            $table->dropIndex('spt_retry_post_idx');
            $table->dropColumn(['fail_code', 'provider_error', 'attempts', 'retry_at', 'retry_provider_post_id']);
        });
    }
};
