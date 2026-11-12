<?php

namespace App\Console\Commands;

use App\Enums\SocialPostTargetStatus;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Services\Analytics;
use App\Support\SignupAttribution;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * New accounts per day and how far they got. Read-only.
 *
 *   php artisan support:signups            the last 7 days
 *   php artisan support:signups --days=30
 *
 * One row per day (UTC): new non-internal accounts, then how many of them
 * have reached each activation milestone so far: a channel connected, a
 * post made (draft or queued), an approval link sent, a post published.
 * Read from the data itself, not from analytics, so it also covers
 * accounts made before the first_* events existed. A second table splits
 * the same accounts by signup_source (SignupAttribution; "unknown" for
 * accounts made before it was kept).
 *
 * Never an email, a name, a handle or an id: counts only. Internal
 * accounts (staff, hello@creatif.space, ANALYTICS_INTERNAL_*) are left out,
 * and their number is said once.
 */
class SupportSignups extends Command
{
    protected $signature = 'support:signups {--days=7 : How many days back, today included (1–90)}';

    protected $description = 'New accounts per day (non-internal), by signup_source, and how many reached each first milestone (read-only, counts only)';

    public const MAX_DAYS = 90;

    /** The milestones, in funnel order: column title => the check. */
    private const MILESTONES = ['Channel', 'Post', 'Approval sent', 'Published'];

    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days < 1 || $days > self::MAX_DAYS) {
            $this->error('--days must be between 1 and '.self::MAX_DAYS.'.');

            return self::FAILURE;
        }

        $today = CarbonImmutable::now('UTC')->startOfDay();
        $from = $today->subDays($days - 1);

        /** @var array<string, array{signups: int, milestones: array<string, int>}> $byDay */
        $byDay = [];
        for ($d = $from; $d->lessThanOrEqualTo($today); $d = $d->addDay()) {
            $byDay[$d->toDateString()] = ['signups' => 0, 'milestones' => array_fill_keys(self::MILESTONES, 0)];
        }
        /** @var array<string, array{signups: int, published: int}> $bySource */
        $bySource = [];
        $internal = 0;

        User::query()
            ->where('created_at', '>=', $from->setTimezone(config('app.timezone')))
            ->orderBy('id')
            ->chunkById(500, function ($users) use (&$byDay, &$bySource, &$internal): void {
                foreach ($users as $user) {
                    if (Analytics::isInternal($user)) {
                        $internal++;

                        continue;
                    }

                    $day = $user->created_at?->copy()->utc()->toDateString();
                    if ($day === null || ! isset($byDay[$day])) {
                        continue;
                    }

                    $reached = $this->milestones($user);
                    $byDay[$day]['signups']++;
                    foreach ($reached as $name => $done) {
                        $byDay[$day]['milestones'][$name] += $done ? 1 : 0;
                    }

                    $source = SignupAttribution::sourceOf($user);
                    $bySource[$source] ??= ['signups' => 0, 'published' => 0];
                    $bySource[$source]['signups']++;
                    $bySource[$source]['published'] += $reached['Published'] ? 1 : 0;
                }
            });

        $this->line("<options=bold>New accounts, last {$days} day(s) (UTC), internal accounts left out</>");
        $rows = [];
        $total = ['signups' => 0, 'milestones' => array_fill_keys(self::MILESTONES, 0)];
        foreach (array_reverse($byDay, true) as $day => $row) {
            $rows[] = [$day, $row['signups'], ...array_map(fn (string $m) => $this->share($row['milestones'][$m], $row['signups']), self::MILESTONES)];
            $total['signups'] += $row['signups'];
            foreach (self::MILESTONES as $m) {
                $total['milestones'][$m] += $row['milestones'][$m];
            }
        }
        $rows[] = ['Total', $total['signups'], ...array_map(fn (string $m) => $this->share($total['milestones'][$m], $total['signups']), self::MILESTONES)];
        $this->table(['Day', 'Sign-ups', ...self::MILESTONES], $rows);

        $this->line('<options=bold>By signup source</>');
        if ($bySource === []) {
            $this->line('No sign-ups in this period.');
        } else {
            uasort($bySource, fn (array $a, array $b) => $b['signups'] <=> $a['signups']);
            $this->table(['Source', 'Sign-ups', 'Published'], array_map(
                fn (string $source, array $row) => [$source, $row['signups'], $this->share($row['published'], $row['signups'])],
                array_keys($bySource),
                $bySource,
            ));
        }

        $this->line("Internal accounts left out: {$internal}.");

        return self::SUCCESS;
    }

    /**
     * Which milestones this account has reached, from its own rows.
     *
     * @return array<string, bool>
     */
    private function milestones(User $user): array
    {
        return [
            'Channel' => SocialAccount::query()->where('user_id', $user->id)->exists(),
            'Post' => SocialPost::query()->where('user_id', $user->id)->exists(),
            'Approval sent' => SocialApprovalLink::query()->where('user_id', $user->id)->exists(),
            'Published' => SocialPostTarget::query()
                ->where('status', SocialPostTargetStatus::Published)
                ->whereHas('post', fn ($q) => $q->where('user_id', $user->id))
                ->exists(),
        ];
    }

    /** "3 (60%)", or "0" when there is nothing to divide by. */
    private function share(int $count, int $of): string
    {
        return $of === 0 ? (string) $count : $count.' ('.(int) round($count / $of * 100).'%)';
    }
}
