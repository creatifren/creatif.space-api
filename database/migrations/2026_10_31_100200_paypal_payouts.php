<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keep historical bank snapshots; new payouts use PayPal only.
        Schema::table('users', function (Blueprint $table) {
            $table->string('payout_paypal_email')->nullable();
        });
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->string('paypal_email')->nullable();
            $table->string('payout_reference')->nullable();
            $table->string('bank_code', 20)->nullable()->change();
            $table->string('account_number', 40)->nullable()->change();
            $table->string('account_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Existing payout records must keep their destination and reference.
    }
};
