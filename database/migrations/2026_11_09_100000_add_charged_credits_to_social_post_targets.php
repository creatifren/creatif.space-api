<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a Credits-paid destination paid, now that the price can differ by
 * platform (X). A return gives back exactly this. Null on rows charged
 * before it — they all paid the flat 300 — and on allowance publishes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_post_targets', function (Blueprint $table) {
            $table->unsignedInteger('charged_credits')->nullable()->after('paid_with');
        });
    }

    public function down(): void
    {
        Schema::table('social_post_targets', function (Blueprint $table) {
            $table->dropColumn('charged_credits');
        });
    }
};
