<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A password on a drop-box link, the same shape a Transfer already has.
     *
     * Nullable is the whole design: most requests are pasted into a chat
     * with somebody who is expected to answer, and asking those to carry a
     * password would be theatre. The column only means something when the
     * owner decided it should.
     */
    public function up(): void
    {
        Schema::table('file_requests', function (Blueprint $table) {
            $table->string('password_hash')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('file_requests', function (Blueprint $table) {
            $table->dropColumn('password_hash');
        });
    }
};
