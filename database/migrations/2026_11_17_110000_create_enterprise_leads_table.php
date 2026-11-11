<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise "Contact sales" requests (POST /api/v1/enterprise/contact).
 * Each is also emailed to the sales inbox; the row is the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_leads', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // Signed in when they asked, if they were.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('email', 254);
            $table->string('company', 160);
            $table->string('team_size', 40);
            $table->text('message');
            $table->timestamps();

            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_leads');
    }
};
