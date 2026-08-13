<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The source of truth for pricing — edited in Filament, read everywhere.
     * No plan number is ever written in code: /pricing renders this table,
     * and quota enforcement reads it too.
     *
     * No ulid here: `key` is already the stable public identity ("premium"),
     * and it is what the checkout endpoint receives.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key', 20)->unique();
            $table->string('name', 50);
            // Money is integer Rupiah, never float. fee_percent is the one
            // decimal in the schema — it is a percentage, not an amount.
            $table->unsignedBigInteger('price_monthly')->default(0);
            $table->unsignedBigInteger('price_yearly')->default(0);
            $table->decimal('fee_percent', 4, 2)->default(0);
            $table->unsignedBigInteger('seat_price_monthly')->default(0);
            // null inside quotas means unlimited — absence of a ceiling, not
            // a ceiling of zero.
            $table->json('quotas');
            $table->json('features');
            // Retire a plan without deleting it: old subscriptions still
            // point at their row.
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
