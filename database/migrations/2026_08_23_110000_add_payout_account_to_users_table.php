<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The saved payout destination (Insights → Orders → Destination account).
     * One per user, editable without withdrawing; each withdrawal still
     * copies the details onto its own row, so history is immune to edits.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('payout_bank_code', 20)->nullable();
            $table->string('payout_account_number', 40)->nullable();
            $table->string('payout_account_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['payout_bank_code', 'payout_account_number', 'payout_account_name']);
        });
    }
};
