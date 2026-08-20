<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Send me your files" as a link. The other side needs no account —
     * they open the link, pick files, and the files land in the owner's
     * storage under requests/{request_ulid}/.
     *
     * Carries a short `slug` as well as the ULID: this address gets pasted
     * into WhatsApp, and 26 characters of base32 is hostile there.
     */
    public function up(): void
    {
        Schema::create('file_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 30)->unique();
            $table->string('title');
            $table->text('note')->nullable();
            // Shown to the sender so the page states its own limits rather
            // than failing after the upload has started.
            $table->unsignedTinyInteger('max_files')->default(5);
            $table->unsignedSmallInteger('max_mb')->default(100);
            $table->string('status', 20)->default('open');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('file_requests');
    }
};
