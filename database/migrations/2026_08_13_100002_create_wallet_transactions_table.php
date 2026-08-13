<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The creator's balance, as a ledger. Append-only: a balance is never
     * UPDATEd, and a mistake is corrected with another row, never by
     * rewriting one (integrity rule §3).
     *
     * The balance is SUM(amount). `balance_after` is a denormalised copy
     * for auditing — if the two ever disagree, the sum is right.
     */
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            // restrict: a ledger outlives the account it belongs to.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            // SIGNED: negative is money leaving. The one signed money column
            // in the schema.
            $table->bigInteger('amount');
            $table->unsignedBigInteger('balance_after');
            // Polymorphic by hand rather than morphs(): the reference is an
            // audit pointer, never something we eager-load through.
            $table->string('reference_type', 30)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'id']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
