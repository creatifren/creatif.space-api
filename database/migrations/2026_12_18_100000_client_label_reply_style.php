<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v10.6 Reply style (Rizal yes 21 Nov 08:30): a client's own style for AI
 * replies — {tone, address, emoji, examples}. Null = the workspace default
 * (social_settings.reply_style). Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_labels', function (Blueprint $table) {
            $table->json('reply_style')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('client_labels', function (Blueprint $table) {
            $table->dropColumn('reply_style');
        });
    }
};
