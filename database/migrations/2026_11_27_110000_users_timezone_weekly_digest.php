<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The weekly digest goes out on Monday 08:00 in the user's timezone:
 * timezone (IANA, null = the app's) and the local Monday the last one
 * was sent for, so an hourly run sends each week once. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable();
            $table->date('weekly_digest_sent_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['timezone', 'weekly_digest_sent_on']);
        });
    }
};
