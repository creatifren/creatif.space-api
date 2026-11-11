<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Google Play Billing beside Creem: the Android app sells the same plans.
 *
 * A Play subscription is identified by its purchase token. Tokens run to
 * hundreds of characters, too long for a unique index on utf8mb4, so the
 * uniqueness lives on a sha256 of it. The linked hash is the token a
 * purchase replaced (upgrade, downgrade, resubscribe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->enum('provider', ['creem', 'google'])->default('creem')->after('plan_id');
            $table->string('google_purchase_token', 512)->nullable()->after('creem_customer_id');
            $table->char('google_purchase_token_hash', 64)->nullable()->unique()->after('google_purchase_token');
            $table->string('google_product_id', 128)->nullable()->after('google_purchase_token_hash');
            $table->string('google_order_id', 128)->nullable()->after('google_product_id');
            $table->char('google_linked_purchase_token_hash', 64)->nullable()->index()->after('google_order_id');
            $table->boolean('google_auto_renewing')->default(false)->after('google_linked_purchase_token_hash');
        });

        // Every row so far came from Creem (the default already says so).
        DB::table('subscriptions')->update(['provider' => 'creem']);
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique(['google_purchase_token_hash']);
            $table->dropIndex(['google_linked_purchase_token_hash']);
            $table->dropColumn([
                'provider', 'google_purchase_token', 'google_purchase_token_hash', 'google_product_id',
                'google_order_id', 'google_linked_purchase_token_hash', 'google_auto_renewing',
            ]);
        });
    }
};
