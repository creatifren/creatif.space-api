<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Something for sale: a service, a digital product, a booking, or a tip.
     * An offer either hangs off a Space or stands on the profile's Hire
     * list — `space_id` null means the latter.
     *
     * Soft deletes because an order placed last month must still be able to
     * say what it was for, even after the offer is taken down.
     */
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('space_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->string('title');
            $table->text('description')->nullable();
            // Null for a tip: the buyer names the amount.
            $table->unsignedBigInteger('price')->nullable();
            $table->char('currency', 3)->default('IDR');
            // "starting at Rp3,5jt" — an indication, not a quote. Services
            // are scoped in conversation; the number is the opening line.
            $table->boolean('price_from')->default(false);
            // The editor's "Where it appears" pair.
            $table->boolean('show_on_space')->default(true);
            $table->boolean('show_on_profile')->default(true);
            // What the editor's Service/Product panels collect: includes,
            // delivery, revisions, licence, source. Shapes differ per type,
            // so it travels as one document rather than eight columns.
            $table->json('details')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'is_active']);
            $table->index(['space_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
