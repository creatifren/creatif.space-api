<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two onboarding emails, each sent at most once per account
 * (App\Support\Onboarding): the welcome email, and the reminder to connect
 * a first channel when none is connected a day after signing up. Set
 * before the email is queued, in one conditional update, so two requests
 * or two scheduler runs can never send it twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('welcome_sent_at')->nullable();
            $table->timestamp('connect_nudge_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['welcome_sent_at', 'connect_nudge_sent_at']);
        });
    }
};
