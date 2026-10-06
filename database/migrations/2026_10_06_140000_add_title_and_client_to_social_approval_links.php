<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The share dialog's two labels: the title the client sees on the page,
     * and who the link is for — the editor's own note, never shown to them.
     */
    public function up(): void
    {
        Schema::table('social_approval_links', function (Blueprint $table) {
            $table->string('title', 120)->nullable()->after('token');
            $table->string('client_name', 120)->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('social_approval_links', function (Blueprint $table) {
            $table->dropColumn(['title', 'client_name']);
        });
    }
};
