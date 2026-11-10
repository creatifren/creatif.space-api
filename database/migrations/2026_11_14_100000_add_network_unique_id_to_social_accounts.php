<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The network's own id for the account (Outstand's network_unique_id).
 * Two relay accounts with the same one are the same account connected
 * twice: Channels lists it once. Null = not reported.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('network_unique_id', 191)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn('network_unique_id');
        });
    }
};
