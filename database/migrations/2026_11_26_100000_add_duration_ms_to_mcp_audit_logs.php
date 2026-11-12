<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long the API took to answer each MCP / API-key request, in
 * milliseconds (mcp:usage reports p50 / p95 per tool). Null on rows written
 * before this column existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_audit_logs', function (Blueprint $table) {
            $table->unsignedInteger('duration_ms')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_audit_logs', function (Blueprint $table) {
            $table->dropColumn('duration_ms');
        });
    }
};
