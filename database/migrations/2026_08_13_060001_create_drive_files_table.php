<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Metadata cache only — the file itself never leaves the user's Drive.
     */
    public function up(): void
    {
        Schema::create('drive_files', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('drive_account_id')->constrained()->cascadeOnDelete();
            $table->string('provider_file_id', 128);
            $table->string('parent_folder_id', 128)->nullable()->index();
            $table->string('name', 512);
            $table->string('mime_type', 128)->index();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('thumbnail_url', 2048)->nullable();
            // Drive md5Checksum/version — the basis for approval auto-cancel.
            $table->string('version_hash', 64)->nullable();
            $table->json('exif')->nullable();
            $table->boolean('is_folder')->default(false);
            $table->timestamp('access_lost_at')->nullable();
            $table->timestamp('trashed_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['drive_account_id', 'provider_file_id']);
        });

        // Fulltext for the browser's search box — MySQL only; the sqlite
        // test database falls back to the LIKE query the controller uses.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('drive_files', function (Blueprint $table) {
                $table->fullText('name');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drive_files');
    }
};
