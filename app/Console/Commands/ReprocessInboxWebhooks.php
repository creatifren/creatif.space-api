<?php

namespace App\Console\Commands;

use App\Contracts\SocialProvider;
use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
use App\Support\InboxEvents;
use App\Support\SocialProviders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Replay stored Inbox webhook events that were never filed: outcome null
 * (received before outcomes were recorded) or dropped:unknown_account.
 * Same handlers as the live webhook, quietly — no bell, no unread counts.
 *
 * Idempotent: comments, messages and threads are upserted by their
 * provider ids, so a second run changes nothing; each row's outcome is
 * rewritten with what happened this time.
 *
 * First (unless --skip-refs) every connected Instagram/Facebook account of
 * the relay that has no network_webhook_reference_id yet learns it from
 * the provider — a read; nothing is changed or deleted there.
 *
 * Dry run by default; --force writes.
 */
class ReprocessInboxWebhooks extends Command
{
    protected $signature = 'inbox:reprocess-webhooks
        {--source=outstand : The relay whose events to replay}
        {--force : Write; without it this is a dry run}
        {--skip-refs : Do not backfill network_webhook_reference_id first}';

    protected $description = 'Replay stored Inbox webhook events that were never filed (outcome null or dropped:unknown_account).';

    public function handle(SocialProviders $providers): int
    {
        $source = (string) $this->option('source');
        $force = (bool) $this->option('force');
        $provider = $providers->named($source);

        if (! $this->option('skip-refs')) {
            $this->backfillRefs($provider, $source, $force);
        }

        $rows = DB::table('webhook_events')
            ->where('source', $source)
            ->where('type', 'like', 'inbox.%')
            ->where(fn ($q) => $q->whereNull('outcome')->orWhere('outcome', 'dropped:unknown_account'))
            ->orderBy('id')
            ->get(['id', 'type', 'outcome', 'payload']);

        $this->info(($force ? '' : '[dry run] ').$rows->count().' stored event(s) to replay.');
        $results = [];

        foreach ($rows as $row) {
            $type = substr((string) $row->type, strlen('inbox.'));
            $data = json_decode((string) $row->payload, true);

            if (! is_array($data)) {
                $results['skipped:bad_payload'] = ($results['skipped:bad_payload'] ?? 0) + 1;

                continue;
            }

            if (! $force) {
                $this->line("  #{$row->id} {$row->type} (was ".($row->outcome ?? 'null').')');

                continue;
            }

            $outcome = DB::transaction(function () use ($provider, $type, $data, $row): string {
                $outcome = (new InboxEvents($provider, live: false))->apply($type, $data);
                DB::table('webhook_events')->where('id', $row->id)->update(['outcome' => substr($outcome, 0, 48)]);

                return $outcome;
            });

            $results[$outcome] = ($results[$outcome] ?? 0) + 1;
            $this->line("  #{$row->id} {$row->type}: ".($row->outcome ?? 'null')." → {$outcome}");
        }

        foreach ($results as $outcome => $n) {
            $this->info("{$outcome}: {$n}");
        }

        return self::SUCCESS;
    }

    /** Connected IG/FB rows learn their webhook reference id (read from the provider). */
    private function backfillRefs(SocialProvider $provider, string $source, bool $force): void
    {
        $accounts = SocialAccount::query()
            ->where('provider', $source)
            ->where('status', SocialAccountStatus::Connected)
            ->whereIn('platform', [SocialPlatform::Instagram, SocialPlatform::Facebook])
            ->whereNull('network_webhook_reference_id')
            ->get();

        $this->info(($force ? '' : '[dry run] ').$accounts->count().' connected account(s) without a webhook reference id.');

        foreach ($accounts as $account) {
            try {
                $remote = $provider->account($account->provider_account_id);
            } catch (Throwable $e) {
                $this->warn("  {$account->provider_account_id}: lookup failed ({$e->getMessage()})");

                continue;
            }

            $ref = is_string($remote['network_webhook_reference_id'] ?? null) ? $remote['network_webhook_reference_id'] : null;
            $this->line("  {$account->platform->value} {$account->provider_account_id}: ".($ref ?? 'none reported'));

            if ($force && $ref !== null && $ref !== '') {
                $account->forceFill(['network_webhook_reference_id' => substr($ref, 0, 64)])->save();
            }
        }
    }
}
