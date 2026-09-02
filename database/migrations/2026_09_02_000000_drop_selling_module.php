<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The selling module is gone: offers, orders, and everything that only
 * existed to describe them.
 *
 * wallet_transactions keeps its sale_credit rows. That money was really
 * earned and some of it really was withdrawn, so deleting the lines would
 * leave balances that no longer add up.
 */
return new class extends Migration
{
    public function up(): void
    {
        // orders first: it carries the FK to offers.
        Schema::dropIfExists('orders');
        Schema::dropIfExists('offers');

        Schema::table('spaces', function (Blueprint $table): void {
            $table->dropColumn('selling_enabled');
        });

        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('fee_percent');
        });

        DB::table('space_events')->where('type', 'order_click')->delete();
    }

    public function down(): void
    {
        // No-op: the dropped tables carried real order history that this
        // migration cannot invent back.
    }
};
