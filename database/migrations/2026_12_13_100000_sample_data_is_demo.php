<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sample data for every new account (prototype v10, Rizal yes 20 Nov): rows
 * made by App\Support\SampleData carry is_demo = true, so "Remove sample
 * data" deletes exactly them, and quotas, Insight, report emails, reminders,
 * publishing, MCP and public pages leave them out. social_accounts.is_demo
 * exists already (reviewer demo channel); sample accounts there are told
 * apart by provider_account_id "sample_…".
 */
return new class extends Migration
{
    private const TABLES = ['client_labels', 'social_posts', 'social_approval_links', 'client_reports', 'spaces', 'blog_posts', 'files', 'activity_log'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->boolean('is_demo')->default(false);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('is_demo');
            });
        }
    }
};
