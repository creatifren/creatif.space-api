<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two balances, one ledger. The product promises affiliate commission
     * is kept apart from sales earnings, and DATABASE.md §I offers this as
     * the simpler of its two options: a column, not a second system.
     *
     * Everything that already exists means `main`, which is why the default
     * is there — no backfill, and every existing row keeps its meaning.
     *
     * The balance is still SUM(amount), now per wallet. Any query that
     * forgets to scope by wallet reads a total that is not anybody's
     * balance, so `Wallet` is the only place allowed to do the sum.
     */
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->string('wallet', 10)->default('main')->after('user_id');
            $table->index(['user_id', 'wallet', 'id']);
        });

        Schema::table('withdrawals', function (Blueprint $table) {
            $table->string('wallet', 10)->default('main')->after('user_id');
            $table->index(['user_id', 'wallet', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'wallet', 'id']);
            $table->dropColumn('wallet');
        });

        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'wallet', 'status']);
            $table->dropColumn('wallet');
        });
    }
};
