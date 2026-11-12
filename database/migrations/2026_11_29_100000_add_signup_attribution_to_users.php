<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * First-touch attribution, written once when the account is created
 * (SignupAttribution::store): utm_source / utm_medium / utm_campaign, the
 * referrer's host, the landing path and the derived signup_source. For
 * support and analytics only — hidden from every serialization, never in
 * an API resource, so no other user (team, client, approver) can see it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('signup_attribution')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('signup_attribution');
        });
    }
};
