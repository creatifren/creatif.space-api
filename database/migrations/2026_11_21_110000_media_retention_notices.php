<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Downgrade notices sent (7 and 1 days before kept files go): one row per
 * owner, notice and deletion date, so each goes out once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_retention_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('days_before');
            $table->date('due_on');
            $table->unsignedInteger('files');
            $table->unsignedBigInteger('bytes');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'days_before', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_retention_notices');
    }
};
