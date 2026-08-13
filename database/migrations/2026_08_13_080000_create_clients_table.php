<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whoever approves — not a full user. Clients have no dashboard: this row
     * is an identity for approvals (and orders in Fase 5), nothing more.
     */
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->string('google_id', 64)->nullable()->unique();
            $table->string('email')->unique();
            $table->string('name')->nullable();
            $table->string('avatar_url', 2048)->nullable();
            // Set when the client's email is also a creator account: that is
            // what lets Insights show "decisions I gave in someone else's
            // Space". nullOnDelete — the approval history outlives the account.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
