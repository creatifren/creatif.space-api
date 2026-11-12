<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the owner was emailed that this post missed its time or failed
 * (SocialPostMissed / SocialPostFailed): one email per post per attempt.
 * Set in one conditional update before the email is queued, so two
 * scheduler runs or a webhook retry can never send it twice; a retry of
 * the post clears it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->timestamp('failure_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropColumn('failure_notified_at');
        });
    }
};
