<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * File Request is gone. The links and their submission records go with
     * it.
     *
     * Files that arrived through a request stay: their bytes are in R2 and
     * the files row never pointed at the request. They keep
     * `source = request`, the same way Drive imports kept theirs.
     *
     * Rows naming the feature go too: "files received" left NotificationType
     * and "request.create" left ActivityAction, and a stored value the enum
     * cast cannot read breaks the whole list it sits in.
     */
    public function up(): void
    {
        Schema::dropIfExists('file_request_submissions');
        Schema::dropIfExists('file_requests');

        DB::table('notification_preferences')->where('type', 'files.received')->delete();
        DB::table('notifications')->where('data->type', 'files.received')->delete();
        DB::table('activity_log')->where('action', 'request.create')->delete();
    }

    /**
     * Structure only. The submissions are not coming back.
     */
    public function down(): void
    {
        Schema::create('file_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 30)->unique();
            $table->string('title');
            $table->text('note')->nullable();
            $table->string('password_hash')->nullable();
            $table->unsignedTinyInteger('max_files')->default(5);
            $table->unsignedSmallInteger('max_mb')->default(100);
            $table->string('status', 20)->default('open');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('file_request_submissions', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('file_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sender_name');
            $table->string('sender_email');
            $table->text('message')->nullable();
            $table->json('files');
            $table->string('status', 20)->default('uploading');
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['file_request_id', 'id']);
            $table->index(['user_id', 'id']);
        });
    }
};
