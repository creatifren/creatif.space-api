<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The id Meta puts in webhooks for this account (Outstand:
 * network_webhook_reference_id — the Instagram/Facebook business account
 * id). Every Outstand account that holds the same IG/FB account shares
 * it, so a webhook that names an Outstand account we never stored (an
 * older connect, purged here) can still be filed where that IG/FB account
 * is connected now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('network_webhook_reference_id', 64)->nullable()->after('network_unique_id');
            $table->index(['platform', 'network_webhook_reference_id'], 'sa_platform_webhook_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropIndex('sa_platform_webhook_ref_idx');
            $table->dropColumn('network_webhook_reference_id');
        });
    }
};
