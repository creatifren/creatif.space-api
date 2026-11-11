<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * webhook_events.created_at was written as app-timezone (WIB) wall time.
 * From now on it is UTC, and this flag says which a row holds — old rows
 * are not rewritten; WebhookEventTime reads both correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->boolean('created_at_utc')->default(false)->after('outcome');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropColumn('created_at_utc');
        });
    }
};
