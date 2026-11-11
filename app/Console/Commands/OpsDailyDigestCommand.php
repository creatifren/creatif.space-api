<?php

namespace App\Console\Commands;

use App\Enums\SocialPostTargetStatus;
use App\Models\SocialPostTarget;
use App\Notifications\OpsDailyDigest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

/**
 * Daily: failed queue jobs and failed publishes in the last 24 hours, mailed
 * to services.ops.alert_email (halo@creatif.space) — only when either count
 * is above zero. A quiet day sends nothing.
 */
class OpsDailyDigestCommand extends Command
{
    protected $signature = 'ops:daily-digest';

    protected $description = 'Mail the team the last 24h of failed jobs and failed publishes (only when there are some)';

    public function handle(): int
    {
        $since = now()->subDay();

        $jobs = collect();
        $table = (string) config('queue.failed.table', 'failed_jobs');
        if (Schema::hasTable($table)) {
            $jobs = DB::table($table)->where('failed_at', '>=', $since)->pluck('payload')
                ->map(fn ($payload) => (string) (json_decode((string) $payload, true)['displayName'] ?? 'unknown'));
        }

        $publishes = SocialPostTarget::query()
            ->where('status', SocialPostTargetStatus::Failed)
            ->where('updated_at', '>=', $since)
            ->pluck('fail_code')
            ->map(fn ($code) => (string) ($code ?: 'unknown'));

        if ($jobs->isEmpty() && $publishes->isEmpty()) {
            $this->info('Nothing failed in the last 24 hours; no mail sent.');

            return self::SUCCESS;
        }

        $count = fn ($c) => $c->countBy()->sortDesc()->take(10)->all();

        Notification::route('mail', (string) config('services.ops.alert_email'))->notify(new OpsDailyDigest(
            $jobs->count(), $count($jobs), $publishes->count(), $count($publishes), $since->utc()->format('j M Y H:i'),
        ));
        $this->info("Sent: {$jobs->count()} failed jobs, {$publishes->count()} failed publishes.");

        return self::SUCCESS;
    }
}
