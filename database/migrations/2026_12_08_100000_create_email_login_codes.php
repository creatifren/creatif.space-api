<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2 · Sign in with an email code (prototype v7, Rizal yes 19 Nov 2026).
 *
 * One screen: Google, or an email + a 6-digit code. A new address becomes an
 * account on the first right code; a known one signs in. Only the code's
 * hash is stored; 10 minutes; 5 tries; single use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_login_codes', function (Blueprint $table) {
            $table->id();
            $table->string('email', 254)->index();
            $table->char('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_login_codes');
    }
};
