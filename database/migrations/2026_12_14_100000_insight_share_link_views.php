<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P7 · Insight › Shared links (prototype v10.1, Rizal yes 20 Nov 2026):
 * "14 views · created by Claude". Additive.
 *
 * - view_count / last_viewed_at: each open of the public page (the owner's
 *   own opens are not counted).
 * - created_via: app | mcp — "created by Claude" when made through MCP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insight_share_links', function (Blueprint $table) {
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->string('created_via', 8)->default('app');
        });
    }

    public function down(): void
    {
        Schema::table('insight_share_links', function (Blueprint $table) {
            $table->dropColumn(['view_count', 'last_viewed_at', 'created_via']);
        });
    }
};
