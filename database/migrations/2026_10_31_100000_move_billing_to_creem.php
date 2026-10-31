<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing moves from Midtrans (IDR) to Creem (USD, merchant of record).
 *
 * From here every amount is integer US cents. Rows written before this
 * migration are marked IDR so their history still reads right; nothing is
 * converted, because nothing was ever sold — the product had not launched.
 *
 * Premium becomes Pro (same row, so subscriptions keep pointing at it); the
 * seeder adds Max and fills in the USD prices.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('plans')->where('key', 'premium')->update(['key' => 'pro', 'name' => 'Pro']);

        Schema::table('plans', function (Blueprint $table) {
            // Team is billed per seat when it ships; there is no extra-seat price.
            $table->dropColumn('seat_price_monthly');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('creem_subscription_id', 64)->nullable()->unique()->after('plan_id');
            $table->string('creem_customer_id', 64)->nullable()->index()->after('creem_subscription_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['midtrans_order_id']);
            $table->dropColumn(['midtrans_order_id', 'midtrans_snap_token']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('currency', 3)->default('USD')->after('amount');
            $table->string('creem_checkout_id', 64)->nullable()->unique()->after('status');
            // Handed back on a repeat click, so one bill never has two checkouts.
            $table->string('creem_checkout_url', 512)->nullable()->after('creem_checkout_id');
            // One Creem transaction pays one invoice: the renewal idempotency handle.
            $table->string('creem_transaction_id', 64)->nullable()->unique()->after('creem_checkout_id');
        });

        Schema::table('credit_purchases', function (Blueprint $table) {
            $table->dropUnique(['midtrans_order_id']);
            $table->dropColumn(['midtrans_order_id', 'midtrans_snap_token']);
        });
        Schema::table('credit_purchases', function (Blueprint $table) {
            $table->unsignedSmallInteger('units')->default(1)->after('credits');
            $table->string('currency', 3)->default('USD')->after('amount');
            $table->string('creem_checkout_id', 64)->nullable()->unique()->after('status');
        });

        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropColumn('midtrans_payout_id');
        });
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->string('currency', 3)->default('USD')->after('amount');
        });

        foreach (['invoices', 'credit_purchases', 'withdrawals'] as $table) {
            DB::table($table)->update(['currency' => 'IDR']);
        }

        // Every webhook seen, once. The unique pair is what makes a retried
        // delivery a no-op; the payload is kept for when money is disputed.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('source', 16);
            $table->string('event_id', 64);
            $table->string('type', 64);
            $table->json('payload');
            $table->timestamps();

            $table->unique(['source', 'event_id']);
        });
    }

    public function down(): void
    {
        // Not reversed: the Midtrans columns carried state that this
        // migration cannot invent back, and the gateway is gone.
    }
};
