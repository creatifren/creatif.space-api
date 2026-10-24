<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The dashboard tour (UX revise RF-004) shows once per account, to people who
 * just signed up. Accounts that already exist are stamped as having seen it:
 * they know their way round, and a tour of a dashboard they have used for
 * weeks would read as a bug.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('tour_seen_at')->nullable()->after('onboarded_at');
        });

        DB::table('users')->update(['tour_seen_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('tour_seen_at');
        });
    }
};
