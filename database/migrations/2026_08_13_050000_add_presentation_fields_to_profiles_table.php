<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Everything the Account Setting screen edits lives on the profile:
     * - mode: 'portfolio' | 'freelance' — the spine of the public page
     * - freelance: JSON for the freelance-only sections (availability,
     *   rates, contact, review visibility). Kept even in portfolio mode
     *   "for the trip back".
     * - categories: work categories (Free plan caps at 3 — enforced in
     *   validation now, from plans.quotas in Fase 5)
     * - cover_url: the cover image
     */
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->string('mode', 20)->default('portfolio')->after('user_id');
            $table->json('freelance')->nullable()->after('seo');
            $table->json('categories')->nullable()->after('freelance');
            $table->string('cover_url', 2048)->nullable()->after('categories');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn(['mode', 'freelance', 'categories', 'cover_url']);
        });
    }
};
