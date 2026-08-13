<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seat changes that have not happened yet.
     *
     * Buying seats mid-period issues a prorated invoice, and `seats` itself
     * must not move until the webhook says that invoice was paid — seats
     * nobody has paid for would otherwise let the owner invite people
     * immediately and never settle. `seats_pending` holds the number the
     * payment is for.
     *
     * Releasing seats is free and takes effect at renewal instead: the
     * current ones are already paid for, and refunding a part-month would
     * mean writing a refund engine behind a toggle. `seats_at_renewal`
     * holds what to drop to.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('seats_pending')->nullable()->after('seats');
            $table->unsignedInteger('seats_at_renewal')->nullable()->after('seats_pending');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['seats_pending', 'seats_at_renewal']);
        });
    }
};
