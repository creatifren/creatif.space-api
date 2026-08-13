<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('spaces', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 80);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('visibility', 20)->default('private');
            $table->string('view_mode', 20)->default('grid');
            $table->string('password_hash')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('approval_enabled')->default(false);
            $table->boolean('selling_enabled')->default(false);
            // Layout source of truth: ordered sections of {item|text} refs.
            $table->json('design');
            $table->json('settings');
            $table->json('seo')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            // Deleted Space = link dies but history stays; the unique index
            // spanning trashed rows means a dead slug is never reissued.
            $table->softDeletes();

            $table->unique(['user_id', 'slug']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spaces');
    }
};
