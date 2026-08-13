<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money leaving for a bank account. Minimum Rp50.000, lands in about two
     * business days.
     *
     * Processed by hand from the admin panel for now; `midtrans_payout_id`
     * is here so Iris/Payouts can be wired later without a migration. A
     * failed payout puts the money back with a `withdrawal_failed_credit`
     * row rather than deleting the debit.
     */
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('bank_code', 20);
            $table->string('account_number', 40);
            $table->string('account_name');
            $table->string('status', 20)->default('pending');
            $table->string('midtrans_payout_id', 64)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
