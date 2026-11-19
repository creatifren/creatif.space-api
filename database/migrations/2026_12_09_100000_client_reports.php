<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M4 · Report emails + monthly client reports (prototype v7, Rizal yes 19 Nov
 * 2026; UX review D5). Additive.
 *
 * - users: Preferences › Report emails — on/off (off until the owner turns
 *   it on: nothing reaches a client by surprise), the day of the month and
 *   time (owner's timezone) it goes out, and the sender name. Per client
 *   on/off is client_labels.report_email (M1).
 * - client_reports: one monthly report per client — its share link (Link
 *   only by default), the editable summary, when and to whom it was sent,
 *   and how often the client opened it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('client_reports_enabled')->default(false);
            $table->unsignedTinyInteger('report_send_day')->default(1);
            $table->string('report_send_time', 5)->default('09:00');
            $table->string('report_from_name', 120)->nullable();
        });

        Schema::create('client_reports', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_label_id')->nullable()->constrained()->nullOnDelete();
            // The reported month, YYYY-MM.
            $table->char('month', 7);
            $table->string('token', 64);
            $table->char('token_hash', 64)->unique();
            // link (default) | public (listed on the owner's profile) | private.
            $table->string('visibility', 10)->default('link');
            $table->text('summary')->nullable();
            $table->timestamp('summary_edited_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('sent_to', 254)->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['client_label_id', 'month']);
            $table->index(['user_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_reports');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['client_reports_enabled', 'report_send_day', 'report_send_time', 'report_from_name']);
        });
    }
};
