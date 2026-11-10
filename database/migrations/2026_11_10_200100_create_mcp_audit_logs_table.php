<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per API call made with an OAuth token or a personal API key:
 * who, which token, which client, which MCP tool, when, and the status.
 * Never the request or response content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_kind', 16);
            // Passport access-token ids are 80 hex chars; API keys are ints.
            $table->string('token_id', 100);
            $table->string('client_id', 100)->nullable();
            $table->string('client_name', 191)->nullable();
            $table->string('tool', 64)->nullable();
            $table->string('route', 120)->nullable();
            $table->string('method', 8);
            $table->unsignedSmallInteger('status');
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at'], 'mcp_audit_user_created_idx');
            $table->index(['token_kind', 'token_id'], 'mcp_audit_token_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_audit_logs');
    }
};
