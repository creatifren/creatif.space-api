<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An Insight share link may name a recipient: it then shows in that
 * person's "Reports shared with you", and they get one email. To list the
 * link back to them, a recipient link keeps its token encrypted with the
 * app key (recipient_token); links without a recipient keep only the hash,
 * as before. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insight_share_links', function (Blueprint $table) {
            $table->string('recipient_email', 254)->nullable()->after('channel_ids');
            $table->text('recipient_token')->nullable()->after('recipient_email');
            $table->timestamp('recipient_notified_at')->nullable()->after('recipient_token');
            $table->index('recipient_email');
        });
    }

    public function down(): void
    {
        Schema::table('insight_share_links', function (Blueprint $table) {
            $table->dropIndex(['recipient_email']);
            $table->dropColumn(['recipient_email', 'recipient_token', 'recipient_notified_at']);
        });
    }
};
