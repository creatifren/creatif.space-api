<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v10.5 Auto DM on comment ("Comment-to-DM", Rizal yes 21 Nov 08:02): a
 * rule per post — keyword, Fixed text (100 Credits a DM) or Written by AI
 * (300), Instagram + Facebook — and a log of the DMs it sent, one per
 * person per post (unique post + commenter). Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            // {keyword, mode: fixed|ai, message, paused_at?}
            $table->json('auto_dm')->nullable();
        });

        Schema::create('social_auto_dms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_comment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('commenter', 191);
            $table->string('status', 20); // sent | failed | no_credits
            $table->unsignedInteger('credits')->default(0);
            $table->string('error', 191)->nullable();
            $table->timestamps();
            $table->unique(['social_post_id', 'commenter']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_auto_dms');
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropColumn('auto_dm');
        });
    }
};
