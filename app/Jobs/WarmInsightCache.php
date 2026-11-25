<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Insight\InsightCollector;
use App\Support\Insight\InsightPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * QA-10 (25 Nov 2026): reads every channel of one Insight answer again in
 * the queue, without the 30 s gateway limit, and keeps it — after a report
 * came back partial ("not read in time") or its 15 minutes ran out. The
 * next load of Insight / the report / MCP is then complete and instant.
 */
class WarmInsightCache implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    /** One warm-up per answer at a time. */
    public int $uniqueFor = 180;

    /** @param  list<int>  $accountIds */
    public function __construct(
        public int $ownerId,
        public array $accountIds,
        public string $kind,
        public string $from,
        public string $to,
    ) {}

    public function uniqueId(): string
    {
        $ids = $this->accountIds;
        sort($ids);

        return implode(':', [$this->ownerId, $this->kind, $this->from, $this->to, sha1(implode(',', $ids))]);
    }

    public function handle(InsightCollector $collector): void
    {
        $owner = User::query()->find($this->ownerId);
        if ($owner === null || $this->accountIds === []) {
            return;
        }
        // Same channels in the same order as the request that asked.
        $byId = SocialAccount::query()->whereIn('id', $this->accountIds)->get()->keyBy('id');
        $accounts = collect($this->accountIds)->map(fn (int $id) => $byId->get($id))->filter()->values();
        if ($accounts->count() !== count($this->accountIds)) {
            return; // A channel was removed meanwhile: its key is gone anyway.
        }

        $tz = config('app.timezone');
        $period = InsightPeriod::fromDates(CarbonImmutable::parse($this->from, $tz), CarbonImmutable::parse($this->to, $tz), $this->kind);

        $collector->refreshCached($owner, $accounts, $period);
    }
}
