<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an MCP OAuth access token was last used to call the API (Settings →
 * Connected apps: "Last used …"). Written by McpTokenResolver at most once
 * per 5 minutes per token; null until first use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_access_tokens', function (Blueprint $table) {
            $table->dateTime('last_used_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('oauth_access_tokens', function (Blueprint $table) {
            $table->dropColumn('last_used_at');
        });
    }

    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
