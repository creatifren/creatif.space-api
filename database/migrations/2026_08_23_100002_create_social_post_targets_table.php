<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // One row per (post, account): the provider reports results per
        // social account, and the UI shows the failure reason per platform.
        Schema::create('social_post_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            // The per-account caption override exactly as sent.
            $table->json('configuration')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('fail_reason')->nullable();
            $table->string('platform_url')->nullable();
            $table->string('provider_result_id', 64)->nullable();
            $table->timestamps();

            $table->unique(['social_post_id', 'social_account_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_post_targets');
    }
};
