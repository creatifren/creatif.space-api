<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Outstand has no per-account caption, so one of our posts with two
     * captions is two provider posts — the provider id belongs to the
     * target, not the post.
     */
    public function up(): void
    {
        Schema::table('social_post_targets', function (Blueprint $table) {
            $table->string('provider_post_id', 64)->nullable()->index()->after('social_account_id');
        });

        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropUnique(['provider_post_id']);
            $table->dropColumn('provider_post_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->string('provider_post_id', 64)->nullable()->unique()->after('user_id');
        });

        Schema::table('social_post_targets', function (Blueprint $table) {
            $table->dropIndex(['provider_post_id']);
            $table->dropColumn('provider_post_id');
        });
    }
};
