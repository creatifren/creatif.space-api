<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M3 · Approval link v7 (Rizal yes 19 Nov 2026; UX review E2, D5). Additive.
 *
 * - views: which views the client can switch between (grid, calendar,
 *   feed — one or more); null = all three, as before.
 * - visibility: link (default — only people with the link) or public
 *   (also listed on the owner's public profile). Never public by default.
 * - replaced_by_id: the newer link that took this one's place; the dead
 *   page then says "replaced" instead of "turned off".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_approval_links', function (Blueprint $table) {
            $table->json('views')->nullable()->after('client_label_id');
            $table->string('visibility', 10)->default('link')->after('views');
            $table->foreignId('replaced_by_id')->nullable()->after('revoked_at')->constrained('social_approval_links')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('social_approval_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaced_by_id');
            $table->dropColumn(['views', 'visibility']);
        });
    }
};
