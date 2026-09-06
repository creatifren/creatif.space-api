<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What I did, in order. Separate from notifications on purpose.
 *
 * A notification is somebody else interrupting me — a client approved, a
 * post failed, storage is nearly full — and every one of them is a thing I
 * would want to be told. My own uploads are not: the Activity screen wants
 * "you published Winter Noel on Tuesday", and routing that through the bell
 * would make it ring for my own typing and turn five preference switches
 * into fourteen.
 *
 * So: notifications stay the eight interrupting kinds, and this is the
 * ledger. The Activity screen reads both.
 *
 * `subject_type`/`subject_id` are a plain pair rather than a polymorphic
 * relation: rows outlive the things they describe (a file is uploaded, then
 * deleted, and "you uploaded it" is still true), so nothing here should
 * ever try to load the subject back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action', 40);
            $table->string('subject_type', 40)->nullable();
            $table->string('subject_id', 40)->nullable();
            // The sentence the screen renders, written where the action
            // happened — it has the names, and this table would only be
            // guessing at them later.
            $table->string('summary');
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            // The screen's only query: mine, newest first, within a range.
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
