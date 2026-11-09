<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Monthly Credits part of what a destination paid; charged_credits is
 * the Top-up part. A return gives each back to its own bucket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_post_targets', function (Blueprint $table) {
            $table->unsignedInteger('charged_monthly')->nullable()->after('charged_credits');
        });
    }

    public function down(): void
    {
        Schema::table('social_post_targets', function (Blueprint $table) {
            $table->dropColumn('charged_monthly');
        });
    }
};
