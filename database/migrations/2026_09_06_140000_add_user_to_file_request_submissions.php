<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who sent it, when we actually know.
 *
 * Submitting stays open to anyone — the link is the product, and no
 * account is needed. This records the sender only when they happened to be
 * signed in, which is the one case where "I sent this" is a fact rather
 * than a claim.
 *
 * `sender_email` is deliberately not used for this. It is validated as an
 * address and never verified, so matching on it would put a stranger's
 * submission in your dashboard the moment they typed your email.
 *
 * nullOnDelete: a deleted account must not take the owner's files with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_request_submissions', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('file_request_id')
                ->constrained()->nullOnDelete();
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('file_request_submissions', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id', 'id']);
            $table->dropColumn('user_id');
        });
    }
};
