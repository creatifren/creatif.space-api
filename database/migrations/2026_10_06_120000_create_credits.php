<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Credits are a third wallet on wallet_transactions — no table of
        // their own. What they need besides the ledger: the storage blocks
        // the account rents, and the top-ups waiting on Midtrans.
        Schema::table('users', function (Blueprint $table) {
            // 5 GB each, renewed on the first of the month from Credits.
            $table->unsignedSmallInteger('storage_blocks')->default(0)->after('theme');
        });

        Schema::create('credit_purchases', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('credits');
            // Rupiah, tax included — Rp100 a Credit is what the user pays.
            $table->unsignedBigInteger('amount');
            $table->string('status', 20)->default('pending');
            // unique: the idempotency handle, same as invoices.
            $table->string('midtrans_order_id', 64)->unique();
            $table->string('midtrans_snap_token', 255)->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('raw_notification')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_purchases');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('storage_blocks');
        });
    }
};
