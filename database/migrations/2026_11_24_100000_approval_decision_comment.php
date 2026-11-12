<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The approver's words with a decision (the reason on "request changes"),
 * kept on the audit row so the audit export can show it. Additive; older
 * rows have none (the export falls back to the APPROVER comment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_approval_decisions', function (Blueprint $table) {
            $table->text('comment')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('social_approval_decisions', function (Blueprint $table) {
            $table->dropColumn('comment');
        });
    }
};
