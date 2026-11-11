<?php

use App\Support\Media\MediaLinks;
use App\Support\Media\StorageRental;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Media v2 (founder, 11 Nov 2026). Additive only — nothing is dropped:
 *
 * - files.kept_at: the owner said "Keep"; retention never takes it.
 * - social_post_files: which library files a post carries. Posts store
 *   media as signed URLs; this is the queryable link the retention job and
 *   the quota read. Backfilled here from every post's URLs.
 * - media_upload_flags: INTERNAL — accounts that keep hitting upload caps.
 * - users.storage_rental_ended_at: storage rental is gone. Every account
 *   still renting blocks is marked ended (storage_blocks is kept as the
 *   record of what they had). Nothing is charged or refunded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->timestamp('kept_at')->nullable()->after('purge_at');
        });

        Schema::create('social_post_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['social_post_id', 'file_id']);
            $table->index('file_id');
        });

        Schema::create('media_upload_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // Cap hits inside the current window.
            $table->unsignedInteger('hits')->default(0);
            $table->unsignedInteger('total_hits')->default(0);
            $table->string('last_reason', 40)->nullable();
            $table->timestamp('window_started_at')->nullable();
            $table->timestamp('last_hit_at')->nullable();
            // Set once hits reach the threshold. Review only — never a ban.
            $table->timestamp('flagged_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('storage_rental_ended_at')->nullable()->after('storage_blocks');
        });

        StorageRental::endAll('migration 2026_11_17_120000_media_v2');
        MediaLinks::backfill();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('storage_rental_ended_at');
        });
        Schema::dropIfExists('media_upload_flags');
        Schema::dropIfExists('social_post_files');
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn('kept_at');
        });
    }
};
