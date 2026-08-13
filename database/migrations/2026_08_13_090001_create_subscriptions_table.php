<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A paid plan, for as long as it is paid for. No row at all means Free —
     * the plan is always derived from here, never stored on the user.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // restrict: a plan that someone is subscribed to can't be deleted
            // out from under them — retire it with is_active instead.
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('billing_period', 10);
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('seats')->default(1);
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            // QRIS and e-wallets can't auto-charge, so a lapsed subscription
            // gets seven days to pay before it falls back to Free.
            $table->timestamp('grace_ends_at')->nullable();
            // Cancelling is a promise about the next cycle, not this one:
            // the subscription stays active until current_period_end.
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('current_period_end');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
