<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A file's earlier bytes.
 *
 * `files` keeps meaning "the file" — its ulid is what Space items, request
 * submissions and every URL point at, and that must not move when a new
 * version lands. The current bytes stay on the `files` row; this table is
 * the history behind it, newest first.
 *
 * Its own table rather than more `files` rows: the library index, the stale
 * upload prune and the unique path index all read `files`, and versions
 * appearing there would show up as separate library entries. The cost is
 * that PlanQuota has to sum both tables — old versions are charged for, so
 * the storage they occupy is visible to whoever can delete them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_versions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();

            // 1-based and gapless per file: v1 is the first bytes ever
            // stored, whatever has been restored since.
            $table->unsignedInteger('number');

            $table->string('disk', 20)->default('s3');
            // Its own object — File::keyFor is ulid-derived, so a version
            // reusing that key would overwrite the very bytes it exists to
            // preserve.
            $table->string('path', 512)->unique();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->json('exif')->nullable();

            // Who put these bytes here, and what they said about them.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 500)->nullable();

            $table->timestamps();

            $table->unique(['file_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_versions');
    }
};
