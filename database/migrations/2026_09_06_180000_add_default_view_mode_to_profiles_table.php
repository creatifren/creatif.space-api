<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which layout a new Space starts in.
     *
     * Per account, not per Space: `spaces.view_mode` already decides one
     * Space and is not going anywhere. This is the answer to "I lay every
     * shoot out the same way and pick Grid every single time".
     *
     * 'editorial' matches what the new-Space modal has always
     * pre-selected, so nobody's next Space changes shape because a column
     * appeared.
     */
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->string('default_view_mode', 20)->default('editorial')->after('mode');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('default_view_mode');
        });
    }
};
