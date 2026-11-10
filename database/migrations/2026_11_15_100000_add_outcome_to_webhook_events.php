<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What became of each stored webhook event: `filed`, or why not
 * (`dropped:unknown_account`, `dropped:own_comment`, …). Queryable in
 * prod whatever the log level — the logs said nothing when a comment
 * arrived but never showed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->string('outcome', 48)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropColumn('outcome');
        });
    }
};
