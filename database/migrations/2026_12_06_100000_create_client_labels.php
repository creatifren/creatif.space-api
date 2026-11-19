<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * M1 · Client labels (prototype v7, Rizal yes 19 Nov 2026). Additive.
 *
 * Clients are labels on social accounts: one account can carry several
 * labels, and a post on that account belongs to all of them. (The table
 * `clients` is taken — it holds approver accounts — hence client_labels.)
 *
 * - client_labels: the owner's labels (name, initials, colour, approver,
 *   whether the monthly report goes to them).
 * - client_label_social_account: label ↔ account, many to many.
 * - social_approval_links.client_label_id: which client a link is for.
 *   Backfill: each distinct (owner, client_name) on existing links becomes a
 *   label (approver = the link's approver_email when there is one) and the
 *   links point at it. client_name stays, for old clients and the audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_labels', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('initials', 3)->nullable();
            $table->string('color', 9)->default('#3E6A8A');
            $table->string('approver_email', 254)->nullable();
            $table->string('approver_name', 120)->nullable();
            // Monthly report email to the approver (Preferences › Report emails).
            $table->boolean('report_email')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });

        Schema::create('client_label_social_account', function (Blueprint $table) {
            $table->foreignId('client_label_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->primary(['client_label_id', 'social_account_id']);
            $table->index('social_account_id');
        });

        Schema::table('social_approval_links', function (Blueprint $table) {
            $table->foreignId('client_label_id')->nullable()->after('client_name')->constrained()->nullOnDelete();
        });

        $now = now();
        DB::table('social_approval_links')
            ->whereNotNull('client_name')
            ->where('client_name', '!=', '')
            ->orderBy('id')
            ->select(['id', 'user_id', 'client_name', 'approver_email'])
            ->chunkById(500, function ($links) use ($now) {
                foreach ($links as $link) {
                    $name = mb_substr(trim((string) $link->client_name), 0, 80);
                    if ($name === '') {
                        continue;
                    }
                    $label = DB::table('client_labels')->where('user_id', $link->user_id)->where('name', $name)->first();
                    if ($label === null) {
                        $id = DB::table('client_labels')->insertGetId([
                            'ulid' => (string) Str::ulid(),
                            'user_id' => $link->user_id,
                            'name' => $name,
                            'initials' => self::initials($name),
                            'color' => '#3E6A8A',
                            'approver_email' => $link->approver_email,
                            'report_email' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    } else {
                        $id = $label->id;
                        if ($label->approver_email === null && $link->approver_email !== null) {
                            DB::table('client_labels')->where('id', $id)->update(['approver_email' => $link->approver_email]);
                        }
                    }
                    DB::table('social_approval_links')->where('id', $link->id)->update(['client_label_id' => $id]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('social_approval_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_label_id');
        });
        Schema::dropIfExists('client_label_social_account');
        Schema::dropIfExists('client_labels');
    }

    private static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = count($words) >= 2
            ? mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1)
            : mb_substr($name, 0, 2);

        return mb_strtoupper($out);
    }
};
