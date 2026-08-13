<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The client's note, and the owner's one reply to it. Append-only —
     * a written note is never edited, so there is no updated_at. The
     * "one owner reply per client note" rule is enforced in the app.
     */
    public function up(): void
    {
        Schema::create('approval_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_id')->constrained()->cascadeOnDelete();
            $table->string('author_type', 10);
            $table->text('body');
            // The revision panel's quick tags ("Colour & tone", "Crop /
            // framing"). Kept apart from the body so they stay filterable.
            $table->json('chips')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['approval_id', 'author_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_notes');
    }
};
