<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Insight PDF reports (design pick #8). One row per export: what was
     * asked, where the job is, and the finished file in storage. The file
     * is short-lived (InsightReport::TTL_HOURS); the row is the receipt.
     *
     * Followers have no history at the relay — only "now" — so the start of
     * a period can only be told from our own daily snapshot, written by
     * RefreshFollowerCount. No snapshot, no number: the report says "—".
     */
    public function up(): void
    {
        Schema::create('insight_reports', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // Who pressed Export, and whose workspace (plan, accounts) it reads.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('client_id', 64);
            $table->string('format', 16);
            $table->string('locale', 5);
            $table->date('period_from');
            $table->date('period_to');
            // channel_ids, show_logo, hide_branding, next_month_notes, period_kind
            $table->json('options');
            $table->string('status', 16)->default('queued');
            $table->string('file_name', 191)->nullable();
            $table->string('path')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->unsignedSmallInteger('page_count')->nullable();
            $table->string('failure_reason', 64)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'created_at']);
        });

        Schema::create('social_account_follower_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->date('on');
            $table->unsignedInteger('followers');
            $table->timestamps();

            $table->unique(['social_account_id', 'on'], 'sa_follower_snap_account_on_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_account_follower_snapshots');
        Schema::dropIfExists('insight_reports');
    }
};
