<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the owner last sent the approver a reminder for this link
 * (POST /social-approval-links/{id}/remind, one per 12 hours). Additive;
 * existing links have never been reminded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_approval_links', function (Blueprint $table) {
            $table->timestamp('last_reminded_at')->nullable()->after('extended_at');
        });
    }

    public function down(): void
    {
        Schema::table('social_approval_links', function (Blueprint $table) {
            $table->dropColumn('last_reminded_at');
        });
    }
};
