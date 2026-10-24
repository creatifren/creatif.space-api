<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-043: Extend keeps the same link and says so — "Extended to 12 Nov by
 * Rani · 2 Nov". Only the owner can extend, so the when is all it needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_approval_links', function (Blueprint $table) {
            $table->timestamp('extended_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('social_approval_links', function (Blueprint $table) {
            $table->dropColumn('extended_at');
        });
    }
};
