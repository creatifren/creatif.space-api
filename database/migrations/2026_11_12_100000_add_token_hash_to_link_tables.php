<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Approval and plan links are looked up by sha256(token), never by the
 * token itself: a leaked database row no longer opens a link. Existing
 * rows are backfilled here, so every link made before this still opens.
 * The plaintext `token` column stays for now (it is how the app shows the
 * URL); a follow-up drops it once this is verified live.
 */
return new class extends Migration
{
    private const TABLES = ['social_approval_links', 'social_plan_links'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->char('token_hash', 64)->nullable()->after('token');
            });

            $this->backfill($table);

            Schema::table($table, function (Blueprint $t) use ($table): void {
                $t->unique('token_hash', $table.'_token_hash_unique');
            });
        }
    }

    /** Hash every row that has no hash yet (all of them, the first time). */
    public function backfill(string $table): void
    {
        DB::table($table)->whereNull('token_hash')->orderBy('id')->chunkById(500, function ($rows) use ($table): void {
            foreach ($rows as $row) {
                DB::table($table)->where('id', $row->id)->update(['token_hash' => hash('sha256', (string) $row->token)]);
            }
        });
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) use ($table): void {
                $t->dropUnique($table.'_token_hash_unique');
                $t->dropColumn('token_hash');
            });
        }
    }
};
