<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membership of Drive files in a Space. Text blocks are NOT rows — they
     * live in spaces.design (no drive_file to reference). section/sort_order
     * mirror the design layout for relational queries; design stays the
     * source of truth for mixed photo/text ordering.
     */
    public function up(): void
    {
        Schema::create('space_items', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('space_id')->constrained()->cascadeOnDelete();
            // restrict: a Drive file can't vanish while a Space shows it;
            // disconnecting an account deletes files → that path must fail
            // loudly until the Space item is removed (or Fase 4 flags it).
            $table->foreignId('drive_file_id')->constrained()->restrictOnDelete();
            $table->string('section', 120)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('caption')->nullable();
            $table->timestamps();

            $table->unique(['space_id', 'drive_file_id']);
            $table->index(['space_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('space_items');
    }
};
