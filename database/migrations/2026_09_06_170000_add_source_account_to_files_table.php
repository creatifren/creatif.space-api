<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which Drive account a file was imported from.
     *
     * The link already existed as `source_meta->drive_email` — text, no key,
     * unindexed. Good enough as provenance on one row, useless as a filter:
     * "show me what came from the second account" had to compare strings
     * across the whole library, and an account that changed its email would
     * silently split into two.
     *
     * nullOnDelete rather than cascade, and it is the whole point: revoking
     * a Drive account must not delete the files it brought. Those bytes are
     * ours now — the import was a one-way copy, not a live mirror.
     */
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->foreignId('source_account_id')
                ->nullable()
                ->after('source')
                ->constrained('drive_accounts')
                ->nullOnDelete();

            // The filter's query: this account's files, newest first.
            $table->index(['user_id', 'source_account_id']);
        });

        /* Backfill from what the JSON already recorded. `source_meta->key`
           rather than whereJsonContains: the latter is unsupported on
           SQLite, which is what the tests run on, and this compiles to
           json_extract on both engines.

           An email that no longer matches any account leaves the column
           null, which reads as "imported from a Drive we no longer hold" —
           true, and better than guessing at which one. */
        foreach (DB::table('drive_accounts')->select('id', 'user_id', 'email')->cursor() as $account) {
            DB::table('files')
                ->where('user_id', $account->user_id)
                ->where('source', 'drive_import')
                ->whereNull('source_account_id')
                ->where('source_meta->drive_email', $account->email)
                ->update(['source_account_id' => $account->id]);
        }
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'source_account_id']);
            $table->dropConstrainedForeignId('source_account_id');
        });
    }
};
