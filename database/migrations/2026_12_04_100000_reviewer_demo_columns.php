<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The directory reviewer account (reviewer:setup): a seeded demo channel
 * that never reaches a network, and a staff-comped plan that is not a Creem
 * subscription. Both default to the ordinary case; idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('social_accounts', 'is_demo')) {
            Schema::table('social_accounts', function (Blueprint $table) {
                $table->boolean('is_demo')->default(false)->after('provider');
            });
        }

        if (! Schema::hasColumn('subscriptions', 'plan_source')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                // null = paid through the provider; 'staff_comp' = given by staff, never billed.
                $table->string('plan_source', 20)->nullable()->after('provider');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('social_accounts', 'is_demo')) {
            Schema::table('social_accounts', fn (Blueprint $table) => $table->dropColumn('is_demo'));
        }
        if (Schema::hasColumn('subscriptions', 'plan_source')) {
            Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn('plan_source'));
        }
    }
};
