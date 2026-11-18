<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Pinterest board last used on a Pinterest channel: a Pin with no
 * board chosen goes there (PinterestBoards), so the app and connected apps
 * do not have to name it every time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('pinterest_default_board_id', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn('pinterest_default_board_id');
        });
    }
};
