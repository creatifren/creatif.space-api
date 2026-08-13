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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->string('name');
            $table->string('email')->unique();
            // Creators authenticate exclusively via Google (no password).
            // Internal staff sign in to Filament /admin with a password and may
            // have no Google identity — hence both columns are nullable.
            $table->string('google_id', 64)->nullable()->unique();
            $table->string('password')->nullable();
            $table->boolean('is_staff')->default(false)->index();
            $table->string('avatar_url', 2048)->nullable();
            $table->string('locale', 5)->default('en');
            $table->string('theme', 10)->default('light');
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspended_reason')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('onboarded_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};
