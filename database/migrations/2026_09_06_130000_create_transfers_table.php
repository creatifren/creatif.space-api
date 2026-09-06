<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Here are your files" as a link — File Request in reverse.
 *
 * Same short `slug` for the same reason: this address is pasted into
 * WhatsApp, and 26 characters of base32 is hostile there. Files are not
 * copied: a transfer points at rows the sender already owns, so sending
 * the same shoot twice costs nothing and deleting the transfer never
 * touches the library.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table): void {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 30)->unique();
            $table->string('title');
            $table->text('note')->nullable();
            $table->string('password_hash')->nullable();
            $table->timestamp('expires_at')->nullable();
            // Counted on the public page, not derived from the event log:
            // the sender's list reads these on every render.
            $table->unsignedInteger('opens')->default(0);
            $table->unsignedInteger('downloads')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('transfer_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transfer_id')->constrained()->cascadeOnDelete();
            /* restrictOnDelete, not cascade: a file inside a live transfer
               must not vanish from under the recipient because the sender
               tidied their library. FileController::destroy already refuses
               for the same reason when a Space shows it. */
            $table->foreignId('file_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);

            $table->unique(['transfer_id', 'file_id']);
        });

        Schema::create('transfer_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transfer_id')->constrained()->cascadeOnDelete();
            /* The address the sender typed. Whoever signs in with it sees
               the transfer in "Received" — the link itself still works for
               anyone who has it, so this is a convenience, never a gate.
               Unverified by nature, which is why it grants no rights the
               URL does not already grant. */
            $table->string('email');
            $table->timestamps();

            $table->unique(['transfer_id', 'email']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_recipients');
        Schema::dropIfExists('transfer_files');
        Schema::dropIfExists('transfers');
    }
};
