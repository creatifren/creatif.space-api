<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // post | reels | story. Sent to the provider as a per-account
        // placement on the platforms that have one (Facebook, Instagram,
        // Threads); the rest post it as an ordinary post.
        Schema::table('social_posts', function (Blueprint $table) {
            $table->string('type', 10)->default('post')->after('caption');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
