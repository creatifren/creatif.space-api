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
        Schema::create('drive_accounts', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Import-only connection: tokens are used to copy picked files
            // into R2, never for live sync. Provider-agnostic on paper;
            // Google-only today.
            $table->string('provider', 20)->default('google');
            $table->string('provider_account_id', 64);
            $table->string('email');
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('status', 20)->default('connected');
            $table->timestamps();

            $table->unique(['user_id', 'provider', 'provider_account_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drive_accounts');
    }
};
