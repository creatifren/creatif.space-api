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
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 20);
            // Post for Me account id (acc_...). Tokens live with the
            // provider, never here — we only hold the mapping.
            $table->string('provider_account_id', 64);
            $table->string('username')->nullable();
            $table->string('profile_photo_url')->nullable();
            $table->string('status', 20)->default('connected');
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'platform', 'provider_account_id']);
            // Webhook payloads arrive with no user context.
            $table->index('provider_account_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
