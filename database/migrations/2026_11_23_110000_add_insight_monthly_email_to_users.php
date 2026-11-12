<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Monthly Insight email: the one-page PDF for last month, on the 1st at
     * 09:00. Opt-in, so off for everyone until they switch it on.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('insight_monthly_email')->default(false);
            // The month (Y-m) last sent, so a retry or a second run never sends twice.
            $table->string('insight_monthly_sent_for', 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['insight_monthly_email', 'insight_monthly_sent_for']);
        });
    }
};
