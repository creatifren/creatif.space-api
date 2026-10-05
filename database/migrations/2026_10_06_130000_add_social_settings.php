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
        // No workspace entity (decided 2026-10-06): the account's posting
        // times, labels, caption templates and saved views live in one JSON
        // document on the user. Small, read whole, written whole.
        Schema::table('users', function (Blueprint $table) {
            $table->json('social_settings')->nullable()->after('storage_blocks');
        });

        Schema::table('social_posts', function (Blueprint $table) {
            $table->json('labels')->nullable()->after('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropColumn('labels');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('social_settings');
        });
    }
};
