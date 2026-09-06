<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Duplicate detection groups a user's files by checksum. Without this the
 * scan is a full table scan per account; with it the group-by is served by
 * the index. Composite on (user_id, checksum) because the question is
 * always "this user's copies", never "everyone's".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table): void {
            $table->index(['user_id', 'checksum']);
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'checksum']);
        });
    }
};
