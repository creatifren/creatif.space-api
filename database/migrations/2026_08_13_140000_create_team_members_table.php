<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * People who work inside somebody else's account.
     *
     * The invite is by email and `member_id` stays null until they sign in
     * with Google — matching on email means the invite link is a
     * convenience, not a secret, and Google is what checks the identity.
     *
     * Removal sets `status = removed` rather than deleting the row: the
     * unique (owner_id, email) then makes a re-invite an update, and who
     * was on the team last quarter stays answerable.
     */
    public function up(): void
    {
        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            // nullOnDelete, not cascade: a member deleting their own account
            // must not quietly erase the seat they occupied.
            $table->foreignId('member_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('role', 20)->default('editor');
            $table->string('status', 20)->default('invited');
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['owner_id', 'email']);
            // Workspace::owner() reads exactly this, once per request.
            $table->index(['member_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_members');
    }
};
