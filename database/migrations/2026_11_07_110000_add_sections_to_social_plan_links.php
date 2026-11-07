<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which parts of the plan the public page shows: grid, calendar, feed.
 * Null = all three.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_plan_links', function (Blueprint $table) {
            $table->json('sections')->nullable()->after('account_ids');
        });
    }

    public function down(): void
    {
        Schema::table('social_plan_links', function (Blueprint $table) {
            $table->dropColumn('sections');
        });
    }
};
