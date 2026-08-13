<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One client's decision on one file. The product promise is "approval
     * recorded per file, with a date" — so this is a row per (item, client),
     * never a flag on the item.
     */
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('space_item_id')->constrained()->cascadeOnDelete();
            // restrict: a client who approved something can't be erased out
            // from under the record that says they approved it.
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->string('cancelled_reason', 30)->nullable();
            // Snapshot, not a reference: the file may move on, this is what
            // was signed off. The comparison that voids the approval.
            $table->string('version_hash_at_approval', 64)->nullable();
            $table->timestamps();

            $table->unique(['space_item_id', 'client_id']);
            $table->index(['space_item_id', 'status']);
            $table->index(['client_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};
