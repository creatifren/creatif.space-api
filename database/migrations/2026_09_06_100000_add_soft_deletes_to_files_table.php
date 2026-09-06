<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trash. Deleting a file stopped being final: the row is kept and the R2
 * object with it, so a restore is a column write rather than a re-upload.
 *
 * `purge_at` is stored, not computed at read time — the countdown a client
 * is shown ("7 days left") has to be the same date the purge job acts on,
 * and a window we later change should not silently move files that are
 * already in the bin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table): void {
            $table->softDeletes();
            $table->timestamp('purge_at')->nullable()->after('deleted_at');
            // The purge job scans on this alone, across every user.
            $table->index('purge_at');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table): void {
            $table->dropIndex(['purge_at']);
            $table->dropColumn(['deleted_at', 'purge_at']);
        });
    }
};
