<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per Space per day, written by AggregateSpaceStats. This is
     * what survives the 90-day prune — the daily chart still works for a
     * Space nobody opened all year.
     *
     * No ULID, deliberately: a stat row is never addressed by a public URL.
     *
     * Crawler hits are counted apart from views rather than inside them. A
     * creator's "1.284 views" must not quietly be 60% GPTBot.
     */
    public function up(): void
    {
        Schema::create('space_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('unique_visitors')->default(0);
            $table->unsignedInteger('ai_crawler_hits')->default(0);
            // [{host, count}], top 8 — enough to render the sources card on
            // Free without touching space_events.
            $table->json('top_referrers')->nullable();
            $table->timestamps();

            // The aggregation job upserts on this pair, so re-running it for
            // a day already done corrects rather than doubles.
            $table->unique(['space_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('space_daily_stats');
    }
};
