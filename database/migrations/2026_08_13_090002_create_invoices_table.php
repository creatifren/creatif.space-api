<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One bill. The webhook is the only thing that may mark it paid — the
     * browser redirect after Snap is UX, and is never trusted.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30)->unique();
            $table->unsignedBigInteger('amount');
            $table->string('status', 20)->default('pending');
            // unique: the idempotency handle. A repeated notification for an
            // order we already settled must find this row and stop.
            $table->string('midtrans_order_id', 64)->nullable()->unique();
            $table->string('midtrans_snap_token', 255)->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            // The last webhook body, kept whole: when money is disputed the
            // audit trail matters more than the disk.
            $table->json('raw_notification')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
