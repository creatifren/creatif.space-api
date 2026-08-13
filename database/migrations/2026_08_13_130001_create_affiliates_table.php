<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Someone approved to refer people. Invite-only by design: the row
     * starts as `applied` and a human approves it in Filament.
     *
     * One per user, and a user is required — a commission has to be
     * payable into a wallet, and a wallet needs an account behind it. The
     * public apply form says "sign in to apply" for exactly this reason.
     *
     * `paid_referrals_count` is the one counter in the schema that is
     * deliberately stored rather than derived (integrity rule §6 names it):
     * it decides the tier, and the tier is snapshotted onto every
     * commission, so recomputing it later would rewrite history.
     */
    public function up(): void
    {
        Schema::create('affiliates', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            // Unique declared separately: chaining ->unique() onto the
            // foreign key definition silently does nothing, and one
            // affiliate row per user is the whole shape of this table.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique('user_id');
            $table->string('status', 20)->default('applied');
            // The code in the URL. Issued at apply time so the approval
            // email can carry it, and never edited afterwards — changing it
            // would rewrite where past signups came from.
            $table->string('code', 30)->unique();
            $table->decimal('tier_percent', 4, 2)->default(20.00);
            $table->unsignedInteger('paid_referrals_count')->default(0);
            $table->json('application');
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('affiliates');
    }
};
