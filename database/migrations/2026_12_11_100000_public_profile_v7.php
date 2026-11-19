<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M6 · Public profile + URLs, and M7 · Region + theme (prototype v7, Rizal
 * yes 19 Nov 2026). Additive.
 *
 * - profiles.layout: creatif.space/{username} as Link in bio or Grid.
 * - profiles.contact: the Contact button — one primary channel (WhatsApp,
 *   Email, Instagram DM or a link) and the others; no form.
 * - profiles.custom_domain (+ verified_at): Pro + Max, CNAME → creatif.space;
 *   once verified it applies to every public page of the account.
 * - link_taps: taps per profile link per day (contact buttons, pages).
 * - users.week_start / time_format (Language & region); theme defaults to
 *   system and an unset one becomes system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->string('layout', 12)->default('link_in_bio');
            $table->json('contact')->nullable();
            $table->string('custom_domain', 253)->nullable()->unique();
            $table->timestamp('custom_domain_verified_at')->nullable();
        });

        Schema::create('link_taps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('target', 60);
            $table->date('day');
            $table->unsignedInteger('taps')->default(0);
            $table->unique(['user_id', 'target', 'day']);
        });

        Schema::table('users', function (Blueprint $table) {
            // 1 = Monday … 7 = Sunday (ISO).
            $table->unsignedTinyInteger('week_start')->default(1);
            $table->string('time_format', 3)->default('24h');
            $table->string('theme', 10)->default('system')->change();
        });
        DB::table('users')->whereNull('theme')->orWhere('theme', '')->update(['theme' => 'system']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['week_start', 'time_format']);
            $table->string('theme', 10)->default('light')->change();
        });
        Schema::dropIfExists('link_taps');
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropUnique(['custom_domain']);
            $table->dropColumn(['layout', 'contact', 'custom_domain', 'custom_domain_verified_at']);
        });
    }
};
