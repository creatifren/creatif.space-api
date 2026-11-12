<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tester feedback: what a signed-in user sent from the app or an MCP
 * client. Kept so nothing is lost if the email to the team bounces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);
            $table->text('message');
            $table->string('page', 500)->nullable();
            $table->string('source', 10)->default('web');
            // Staff and test accounts (Analytics::isInternal), so triage can skip them.
            $table->boolean('internal')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
