<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One purchase. The fee is written down here at the moment of sale and
     * never read from `plans` again: a creator who upgrades next month must
     * not retroactively change what last month's sale cost them.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            // restrict on both sides: money changed hands between these two,
            // and neither may be erased out from under the record.
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            // Null for a straight tip — there was no offer, just a thank-you.
            $table->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
            // Where the sale came from, for attribution.
            $table->foreignId('space_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount');
            // Snapshot, not a reference (integrity rule §2).
            $table->decimal('fee_percent', 4, 2);
            $table->unsignedBigInteger('fee_amount');
            $table->unsignedBigInteger('net_amount');
            $table->string('status', 20)->default('pending');
            $table->string('midtrans_order_id', 64)->nullable()->unique();
            $table->string('payment_method', 30)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('raw_notification')->nullable();
            $table->timestamps();

            $table->index(['creator_id', 'status']);
            $table->index('client_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
