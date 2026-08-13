<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Raw traffic. One row per thing a visitor did in a published Space —
     * the only place Insights → Analytics gets its numbers from.
     *
     * No ULID: nothing ever addresses one of these rows by URL, and at this
     * volume a 26-char column per row is real disk. Same reasoning as plans.
     *
     * `visitor_hash` is not PII by construction: the salt rotates daily
     * (App\Support\VisitorHash), so the same browser is a different hash
     * tomorrow. That also *defines* "unique visitors" as per browser, per
     * day — which is what the dashboard claims it counts.
     *
     * Pruned after 90 days once aggregated into space_daily_stats.
     */
    public function up(): void
    {
        Schema::create('space_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->char('visitor_hash', 40);
            // Set when the viewer happens to be signed in as a client — the
            // "who opened it" row Premium analytics shows. Most rows are null.
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            // The item looked at, for lightbox_open / download. Restrict would
            // outlive its use here: an event about a deleted item is noise.
            $table->foreignId('space_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('referrer_host')->nullable();
            $table->boolean('is_ai_crawler')->default(false);
            // Which crawler, so the "who crawled your pages" table can name
            // them. Null whenever is_ai_crawler is false.
            $table->string('crawler_name', 40)->nullable();
            $table->char('country', 2)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['space_id', 'created_at']);
            $table->index(['space_id', 'type', 'created_at']);
            // The prune sweep scans this alone, across every Space.
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('space_events');
    }
};
