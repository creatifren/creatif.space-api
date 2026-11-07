<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content Plan share links: a read-only window onto the owner's posts in a
 * date range, for some or all of their accounts. The posts are read live on
 * every open, so the link follows the plan as it changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_plan_links', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('title', 120)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            // Internal social_accounts ids; null = every account.
            $table->json('account_ids')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_plan_links');
    }
};
