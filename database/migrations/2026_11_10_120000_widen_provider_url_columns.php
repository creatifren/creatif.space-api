<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provider URLs outgrow varchar(255): an Instagram CDN avatar is ~650
 * chars (prod 10 Nov, SQLSTATE 1406). Neither column is indexed, so TEXT.
 * The other provider URL columns are TEXT/JSON already
 * (social_conversations.contact_avatar_url, social_messages.media_urls).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->text('profile_photo_url')->nullable()->change();
        });
        Schema::table('social_post_targets', function (Blueprint $table) {
            $table->text('platform_url')->nullable()->change();
        });
    }

    /** Back to varchar(255); a longer URL is dropped (null) rather than truncated. */
    public function down(): void
    {
        DB::table('social_accounts')->whereRaw('LENGTH(profile_photo_url) > 255')->update(['profile_photo_url' => null]);
        DB::table('social_post_targets')->whereRaw('LENGTH(platform_url) > 255')->update(['platform_url' => null]);

        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('profile_photo_url')->nullable()->change();
        });
        Schema::table('social_post_targets', function (Blueprint $table) {
            $table->string('platform_url')->nullable()->change();
        });
    }
};
