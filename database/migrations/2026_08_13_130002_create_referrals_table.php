<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The funnel: a click, then maybe a signup, then maybe a payment.
     *
     * `referred_user_id` is null for a click that never became an account —
     * that is what makes the "Clicks" figure on /referral a real number
     * rather than a guess, and it is unique so nobody can be referred twice.
     *
     * `cookie_expires_at` governs whether the *first* payment counts, not
     * the twelve months after it. Someone who signs up on day 89 and pays
     * on day 100 still earns: the window's job was to attribute the signup.
     */
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('referred_user_id')->nullable()->unique()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('signed_up_at')->nullable();
            $table->timestamp('first_paid_at')->nullable();
            $table->timestamp('cookie_expires_at');
            $table->boolean('discount_applied')->default(false);
            $table->timestamps();

            $table->index(['affiliate_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
