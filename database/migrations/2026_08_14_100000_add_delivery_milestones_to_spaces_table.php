<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The delivery log's durable facts.
 *
 * Insights → Orders → Delivery promises "this record only grows; it is never
 * edited or deleted. Spaces you have deleted still live here, forever, on
 * every plan." Nothing could honour that yet:
 *
 *   - space_events answers "when was it first opened / downloaded", but
 *     PruneSpaceEvents drops those rows after 90 days. A record that
 *     evaporates at 90 days is not the one that sentence describes.
 *   - space_daily_stats survives the prune, but it aggregates views only —
 *     no downloads, and no *first* anything.
 *
 * So the three milestones get their own columns, on `spaces`, written once
 * and never recomputed. Columns rather than a new table because each is a
 * single timestamp with exactly one row per Space, and because a soft-deleted
 * Space keeps its row — which is precisely what makes "deleted Spaces still
 * live here" true without any extra machinery.
 *
 * `first_opened_at` and `first_downloaded_at` are first-write-wins: the
 * question is when delivery happened, not how often. `downloaded_files`
 * records how much was taken on that first download, since "24 files ·
 * 13 July" is the line the card prints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->timestamp('first_opened_at')->nullable()->after('published_at');
            $table->timestamp('first_downloaded_at')->nullable()->after('first_opened_at');
            $table->unsignedInteger('downloaded_files')->nullable()->after('first_downloaded_at');
        });
    }

    public function down(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->dropColumn([
                'first_opened_at',
                'first_downloaded_at',
                'downloaded_files',
            ]);
        });
    }
};
