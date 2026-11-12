<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an approval decision was made: the /a page ("web") or an MCP
 * client acting for the signed-in approver ("mcp"). Additive; existing
 * rows are web.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_approval_decisions', function (Blueprint $table) {
            $table->string('source', 10)->default('web')->after('decision');
        });
    }

    public function down(): void
    {
        Schema::table('social_approval_decisions', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
