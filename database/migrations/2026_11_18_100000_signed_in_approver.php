<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signed-in approver (founder, Meeting #02, 11 Nov 2026). Additive only.
 *
 * - social_approval_links.approver_email: who may decide on the link.
 *   Null on links made before this: the first signed-in person to decide
 *   binds it (approver_bound_at).
 * - social_approval_decisions: the audit trail — every approve, request
 *   for changes and take-back, with who (user + email), from where (ip,
 *   user agent) and the display name they typed.
 * - approver_login_tokens: one-time email sign-in links for approvers
 *   (hash only; 15 minutes; single use).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_approval_links', function (Blueprint $table) {
            $table->string('approver_email', 254)->nullable()->after('client_name');
            $table->timestamp('approver_bound_at')->nullable()->after('approver_email');
            $table->index('approver_email');
        });

        Schema::create('social_approval_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_approval_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();
            // approved | changes_requested | retracted
            $table->string('decision', 20);
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approver_email', 254)->nullable();
            // What the approver typed as their name, for display only.
            $table->string('client_name', 120)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['social_approval_link_id', 'created_at'], 'sad_link_created_idx');
        });

        Schema::create('approver_login_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_approval_link_id')->constrained()->cascadeOnDelete();
            $table->string('email', 254);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approver_login_tokens');
        Schema::dropIfExists('social_approval_decisions');
        Schema::table('social_approval_links', function (Blueprint $table) {
            $table->dropIndex(['approver_email']);
            $table->dropColumn(['approver_email', 'approver_bound_at']);
        });
    }
};
