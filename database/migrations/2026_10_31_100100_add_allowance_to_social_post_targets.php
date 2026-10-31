<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What paid for each destination: the plan's monthly allowance or Credits.
 *
 * A publish that fails or is cancelled gives its use back to where it came
 * from, so the row has to remember which. `charged_at` places it in an
 * allowance period; both go back to null when the use is returned, which
 * is also what stops a second return from paying twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_post_targets', function (Blueprint $table) {
            $table->string('paid_with', 10)->nullable()->after('status');
            $table->timestamp('charged_at')->nullable()->after('paid_with');
            $table->index(['paid_with', 'charged_at']);
        });
    }

    public function down(): void
    {
        Schema::table('social_post_targets', function (Blueprint $table) {
            $table->dropIndex(['paid_with', 'charged_at']);
            $table->dropColumn(['paid_with', 'charged_at']);
        });
    }
};
