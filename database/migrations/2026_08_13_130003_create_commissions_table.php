<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One payment earns twelve commissions, paid a month apart.
     *
     * All twelve rows are written at once, when the payment lands. Creating
     * them lazily would need a scheduler that can silently skip a month;
     * twelve rows are twelve facts, written once.
     *
     * `hold_until` resolves a contradiction between the docs: DATABASE.md
     * said "created + 30 days" for all twelve, PROJECT.md said "twelve
     * monthly parts, 30-day holding". Those are different schedules. The
     * English means both at once:
     *
     *     hold_until = created + 30 days + (installment_no - 1) months
     *
     * so part 1 releases on day 30 and part 12 eleven months after that.
     *
     * `tier_percent` is snapshotted (integrity rule §2): a tier bump next
     * month must not silently reprice what was earned last month.
     *
     * The unique index is the idempotency key. Midtrans retries by
     * contract, so the webhook re-enters this code — the index refuses the
     * duplicate, which is cheaper and more honest than a status check.
     */
    public function up(): void
    {
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_id')->constrained()->cascadeOnDelete();
            // restrict: a commission must always be able to name the
            // payment it came from.
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->decimal('tier_percent', 4, 2);
            $table->unsignedTinyInteger('installment_no');
            $table->string('status', 20)->default('holding');
            $table->timestamp('hold_until');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['invoice_id', 'installment_no']);
            // The release sweep reads exactly this pair.
            $table->index(['status', 'hold_until']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
