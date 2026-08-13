<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One delivery: who sent it, and what arrived.
     *
     * `status` exists because the upload to Drive happens in a job, after
     * the sender has already been told their files went through. Without
     * it the owner cannot tell "4 files received" from "4 files still in
     * flight" — and that is the exact sentence the screen renders.
     *
     * `files` is the JSON list of what landed: name plus the Drive id, so
     * the owner can be linked straight to the file rather than to a folder.
     */
    public function up(): void
    {
        Schema::create('file_request_submissions', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('file_request_id')->constrained()->cascadeOnDelete();
            $table->string('sender_name');
            $table->string('sender_email');
            $table->text('message')->nullable();
            // [{name, provider_file_id}] — written by the job as each file
            // lands, so a partial upload still shows what got through.
            $table->json('files');
            $table->string('status', 20)->default('uploading');
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['file_request_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('file_request_submissions');
    }
};
