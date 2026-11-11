<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account deletion (Play Store requirement). Additive:
 *
 * - users.deletion_requested_at: when the person asked; purge runs 30 days
 *   later (users.status = pending_deletion meanwhile).
 * - social_approval_decisions keeps its rows when the post or link they
 *   name is purged: the audit trail of who approved what outlives the
 *   content (both foreign keys become nullable, SET NULL on delete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deletion_requested_at')->nullable()->after('suspended_reason')->index();
        });

        Schema::table('social_approval_decisions', function (Blueprint $table) {
            $table->dropForeign(['social_approval_link_id']);
            $table->dropForeign(['social_post_id']);
        });
        Schema::table('social_approval_decisions', function (Blueprint $table) {
            $table->foreignId('social_approval_link_id')->nullable()->change();
            $table->foreignId('social_post_id')->nullable()->change();
            $table->foreign('social_approval_link_id')->references('id')->on('social_approval_links')->nullOnDelete();
            $table->foreign('social_post_id')->references('id')->on('social_posts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('social_approval_decisions', function (Blueprint $table) {
            $table->dropForeign(['social_approval_link_id']);
            $table->dropForeign(['social_post_id']);
        });
        Schema::table('social_approval_decisions', function (Blueprint $table) {
            $table->foreign('social_approval_link_id')->references('id')->on('social_approval_links')->cascadeOnDelete();
            $table->foreign('social_post_id')->references('id')->on('social_posts')->cascadeOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['deletion_requested_at']);
            $table->dropColumn('deletion_requested_at');
        });
    }
};
