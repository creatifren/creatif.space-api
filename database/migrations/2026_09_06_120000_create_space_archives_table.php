<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per built "Download all" archive.
 *
 * A row, not a cache entry: the zip is a real object in the bucket that has
 * to be deleted later, and something has to remember where it is. `status`
 * is what the viewer polls while the job runs — a request that took two
 * minutes and returned bytes would time out long before it finished.
 *
 * `signature` is the Space's content at build time (item ulids, in order).
 * Publish a new photo and the signature changes, so the next visitor gets a
 * fresh build rather than yesterday's archive missing a file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('space_archives', function (Blueprint $table): void {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('space_id')->constrained()->cascadeOnDelete();
            $table->string('signature', 64);
            $table->string('status', 20)->default('pending');
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('failure_reason')->nullable();
            // The cleanup job scans this alone, across every Space.
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            // "Is there already an archive for exactly this content?"
            $table->index(['space_id', 'signature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('space_archives');
    }
};
