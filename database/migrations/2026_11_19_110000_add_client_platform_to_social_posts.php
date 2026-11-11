<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a post was made (web | android_app | android_twa, from the app's
 * X-Creatif-Platform header). post_published fires from a webhook or job,
 * long after the request, so it reads the platform from here. Analytics only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->string('client_platform', 20)->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropColumn('client_platform');
        });
    }
};
