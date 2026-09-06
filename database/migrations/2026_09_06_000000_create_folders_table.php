<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Folders inside the file library. Purely how the owner sees their
     * files here — nothing moves in R2, the object key never changes.
     * `parent_id` NULL is the root ("My Drive").
     */
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('folders')->cascadeOnDelete();
            $table->string('name', 255);
            $table->timestamps();

            $table->index(['user_id', 'parent_id']);
            // ponytail: no unique(user_id, parent_id, name) — a NULL parent
            // defeats it on MySQL. Duplicate names are tolerated.
        });

        Schema::table('files', function (Blueprint $table) {
            // Deleting a folder drops its files back to the root, never
            // deletes them.
            $table->foreignId('folder_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folder_id');
        });
        Schema::dropIfExists('folders');
    }
};
