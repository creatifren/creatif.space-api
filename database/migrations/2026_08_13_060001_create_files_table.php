<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An asset hosted on our storage (Cloudflare R2 via the s3 disk).
     * Rows are immutable once ready — replacing a file means a new row.
     *
     * `status` exists because direct upload is two-phase: presign creates
     * the row, the browser PUTs to R2, complete flips it to ready. A row
     * stuck in pending is a dead upload and gets pruned.
     */
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 20)->default('s3');
            // Object key: u/{user_ulid}/{file_ulid}.{ext} for uploads and
            // imports, requests/{request_ulid}/... for submissions.
            $table->string('path', 512)->unique();
            $table->string('name', 512);
            $table->string('mime_type', 128)->index();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->string('status', 20)->default('pending'); // pending|ready|failed
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->json('exif')->nullable();
            // Provenance, display-only: upload|drive_import|request.
            $table->string('source', 20)->default('upload');
            $table->json('source_meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        // Fulltext for the browser's search box — MySQL only; the sqlite
        // test database falls back to the LIKE query the controller uses.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('files', function (Blueprint $table) {
                $table->fullText('name');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
