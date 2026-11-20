<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v10.2 First comment (Rizal option A, 20 Nov 2026): Compose's First
 * comment text, posted as the first comment on Instagram, Facebook and
 * LinkedIn right after the post goes live (PostFirstComment). Per account:
 * when it went out, or why not — so it is sent once. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->text('first_comment')->nullable();
        });
        Schema::table('social_post_targets', function (Blueprint $table) {
            $table->timestamp('first_comment_at')->nullable();
            $table->string('first_comment_error', 191)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('social_post_targets', function (Blueprint $table) {
            $table->dropColumn(['first_comment_at', 'first_comment_error']);
        });
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropColumn('first_comment');
        });
    }
};
