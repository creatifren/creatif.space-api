<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every publish attempt of a destination, kept: the target row holds only
 * the latest state, and a retry clears it. One row per attempt and
 * outcome (published, failed, retrying — a limit being waited out — or
 * retry_requested by the owner), with the stable failure code and the
 * reason a person reads. Never the provider's raw text.
 *
 * Also: the code for a refusal without a reason is now network_rejected
 * (was unknown). Additive; the rename is a data update only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_post_publish_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_post_target_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt');
            $table->string('outcome', 20);
            $table->string('failure_code', 40)->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('provider_post_id')->nullable();
            $table->string('source', 20);
            $table->timestamps();
            $table->unique(['social_post_target_id', 'attempt', 'outcome'], 'publish_attempts_once');
            $table->index(['social_post_id', 'created_at']);
        });

        DB::table('social_post_targets')->where('fail_code', 'unknown')->update(['fail_code' => 'network_rejected']);
    }

    public function down(): void
    {
        DB::table('social_post_targets')->where('fail_code', 'network_rejected')->update(['fail_code' => 'unknown']);

        Schema::dropIfExists('social_post_publish_attempts');
    }
};
