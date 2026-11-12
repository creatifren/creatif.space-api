<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shareable Insight links: a read-only page of one workspace's numbers
     * for one period and set of channels. Only the token's hash is stored;
     * the token itself is shown once, when the link is made. 30 days, then
     * expired; revocable before that.
     */
    public function up(): void
    {
        Schema::create('insight_share_links', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->date('period_from');
            $table->date('period_to');
            $table->string('period_kind', 8);
            $table->json('channel_ids');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insight_share_links');
    }
};
