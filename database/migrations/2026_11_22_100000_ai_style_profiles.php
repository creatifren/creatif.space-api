<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_style_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('tone', 100)->nullable();
            $table->string('emoji_use', 10)->default('light');
            $table->unsignedTinyInteger('hashtag_count')->default(5);
            $table->string('language', 35)->nullable();
            $table->json('sample_captions')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_style_profiles');
    }
};
