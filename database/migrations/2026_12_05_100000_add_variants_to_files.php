<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Thumbnail / feed / poster variants of a library file for the approval
 * page and plan links (MediaVariants). Null until made; additive, idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('files', 'variants')) {
            Schema::table('files', function (Blueprint $table) {
                $table->json('variants')->nullable()->after('exif');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('files', 'variants')) {
            Schema::table('files', function (Blueprint $table) {
                $table->dropColumn('variants');
            });
        }
    }
};
