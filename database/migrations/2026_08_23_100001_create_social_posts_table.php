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
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Null until the provider accepts the post; unique so webhook
            // redeliveries land on exactly one row.
            $table->string('provider_post_id', 64)->nullable()->unique();
            $table->text('caption');
            $table->json('media')->nullable();
            // Null means "posted now" — the provider publishes immediately.
            $table->timestamp('scheduled_at')->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->text('fail_reason')->nullable();
            // Last webhook payload, kept raw for debugging (midtrans pattern).
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};
