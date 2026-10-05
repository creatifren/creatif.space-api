<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Approval rides on the post: null = off. A post waiting for it is
        // held here and never reaches the provider until it is approved —
        // provider_post_id stays null until then.
        Schema::table('social_posts', function (Blueprint $table) {
            $table->string('approval', 20)->nullable()->after('status');
            $table->string('approval_by', 120)->nullable()->after('approval');
            $table->timestamp('approval_at')->nullable()->after('approval_by');
        });

        // One link per request, shared by hand (WhatsApp). The token is the
        // only credential: no account on the other side, by design.
        Schema::create('social_approval_links', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('social_approval_link_post', function (Blueprint $table) {
            $table->foreignId('social_approval_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();
            $table->primary(['social_approval_link_id', 'social_post_id']);
        });

        // Append-only: resolving hides a thread, it never deletes one. The
        // author is a user (the editor) or a name typed on the link.
        Schema::create('social_post_comments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('social_post_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name', 120);
            $table->string('role', 20);
            $table->text('body');
            // A caption suggestion rides on a comment; Apply copies it over.
            $table->text('suggestion')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_post_comments');
        Schema::dropIfExists('social_approval_link_post');
        Schema::dropIfExists('social_approval_links');
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropColumn(['approval', 'approval_by', 'approval_at']);
        });
    }
};
