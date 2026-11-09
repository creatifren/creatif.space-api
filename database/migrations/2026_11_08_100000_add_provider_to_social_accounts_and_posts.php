<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which relay holds the row. provider_account_id and provider_post_id are
 * that relay's ids and mean nothing to another, so every existing row —
 * all made through Post for Me — says so; new rows take the configured one.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['social_accounts', 'social_posts'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('provider', 20)->default('postforme')->after('user_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['social_accounts', 'social_posts'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('provider');
            });
        }
    }
};
