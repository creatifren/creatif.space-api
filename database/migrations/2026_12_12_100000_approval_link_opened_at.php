<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prototype v10 approval link card: "Sent 2 days ago · opened / not opened"
 * (Rizal yes 20 Nov 2026). Additive: when someone other than the owner
 * first opened the link. Null = not opened yet (and every older link).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_approval_links', function (Blueprint $table) {
            $table->timestamp('opened_at')->nullable()->after('approver_bound_at');
        });
    }

    public function down(): void
    {
        Schema::table('social_approval_links', function (Blueprint $table) {
            $table->dropColumn('opened_at');
        });
    }
};
