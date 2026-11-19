<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5 · Spaces = Gallery or Blog (prototype v7, Rizal yes 19 Nov 2026; UX
 * review H2). Additive, plus one value rename that keeps today's behaviour.
 *
 * - spaces.type: gallery (portfolio, or a media kit with downloads on) or
 *   blog. Every existing Space is a gallery.
 * - spaces.visibility gains "link" (reachable by link, not listed on the
 *   profile). Until now "private" Spaces were reachable by link once
 *   published, so they become "link"; "private" now means nobody but the
 *   owner. New Spaces default to link.
 * - space_items: featured, hidden, a title, and the social post it came from.
 * - blog_posts: the Blog's posts — cover, title, blocks, and the approval
 *   path (needs_approval → an approval link → approved → published). The
 *   public Blog only ever shows published posts.
 * - social_post_space: a social post's "Also add to" Gallery / Blog; it
 *   lands there when the post is published (after approval if it needs one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->string('type', 10)->default('gallery')->after('user_id');
            $table->string('visibility', 20)->default('link')->change();
        });
        DB::table('spaces')->where('visibility', 'private')->update(['visibility' => 'link']);

        Schema::table('space_items', function (Blueprint $table) {
            $table->string('title', 160)->nullable()->after('section');
            $table->boolean('featured')->default(false)->after('caption');
            $table->boolean('hidden')->default(false)->after('featured');
            $table->foreignId('social_post_id')->nullable()->after('file_id')->constrained()->nullOnDelete();
        });

        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_post_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 200);
            $table->string('slug', 160);
            $table->foreignId('cover_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->json('blocks')->nullable();
            // draft | waiting | changes_requested | approved | published
            $table->string('status', 20)->default('draft');
            $table->boolean('needs_approval')->default(false);
            $table->foreignId('social_approval_link_id')->nullable()->constrained()->nullOnDelete();
            $table->string('approval_by', 120)->nullable();
            // The client's note: what to change, or the note that came with the yes.
            $table->text('approval_comment')->nullable();
            $table->timestamp('approval_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['space_id', 'slug']);
            $table->index(['space_id', 'status', 'published_at']);
        });

        Schema::create('social_post_space', function (Blueprint $table) {
            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('space_id')->constrained()->cascadeOnDelete();
            $table->timestamp('added_at')->nullable();
            $table->primary(['social_post_id', 'space_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_post_space');
        Schema::dropIfExists('blog_posts');
        Schema::table('space_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('social_post_id');
            $table->dropColumn(['title', 'featured', 'hidden']);
        });
        DB::table('spaces')->where('visibility', 'link')->update(['visibility' => 'private']);
        Schema::table('spaces', function (Blueprint $table) {
            $table->dropColumn('type');
            $table->string('visibility', 20)->default('private')->change();
        });
    }
};
