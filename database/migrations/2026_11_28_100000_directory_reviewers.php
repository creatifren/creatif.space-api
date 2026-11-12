<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Directory reviewers (Claude Connectors Directory, ChatGPT Apps): a
 * staff-set flag that lets one existing account sign in with a password
 * at POST /auth/reviewer/login, and the audit of every attempt there.
 * Set only by `php artisan reviewer:grant`. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_directory_reviewer')->default(false);
        });

        Schema::create('directory_reviewer_logins', function (Blueprint $table) {
            $table->id();
            // Null for an address with no account: the attempt is still recorded.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // sha256 of the lower-cased address tried — never the address itself.
            $table->string('email_hash', 64);
            $table->string('ip', 45)->nullable();
            // success | rejected | suspended | throttled | granted | revoked
            $table->string('outcome', 20);
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_reviewer_logins');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_directory_reviewer');
        });
    }
};
