<?php

namespace App\Console\Commands;

use App\Casts\UtcDateTime;
use App\Enums\SocialPostStatus;
use App\Enums\SpaceStatus;
use App\Models\SocialPost;
use App\Models\User;
use App\Support\PlanQuota;
use App\Support\Workspace;
use Illuminate\Console\Command;

/**
 * Read-only: what the plans v2 limits find already in place. Nothing is
 * changed — existing Spaces, queued posts and approval links stay as they
 * are; the limits only refuse new ones. Run it after the migration to
 * know how many workspaces sit past a limit.
 */
class PlansV2Report extends Command
{
    protected $signature = 'plans:v2-report';

    protected $description = 'Count existing rows past the plans v2 limits (read-only)';

    public function handle(): int
    {
        $totals = [
            'workspaces' => 0,
            'spaces_workspaces' => 0, 'spaces' => 0, 'spaces_published' => 0,
            'beyond_window_workspaces' => 0, 'beyond_window_posts' => 0,
            'over_queue_workspaces' => 0, 'over_queue_posts' => 0,
            'over_clients_workspaces' => 0,
        ];

        foreach (User::query()->lazyById() as $user) {
            if (Workspace::isMember($user)) {
                continue;
            }

            $days = PlanQuota::scheduleWindowDays($user);
            $queue = PlanQuota::queueLimit($user);
            $clients = PlanQuota::approvalClientsLimit($user);
            $spacesOff = ! PlanQuota::hasFeature($user, 'spaces');
            if ($days === null && $queue === null && $clients === null && ! $spacesOff) {
                continue;
            }
            $totals['workspaces']++;

            if ($spacesOff && ($n = $user->spaces()->count()) > 0) {
                $totals['spaces_workspaces']++;
                $totals['spaces'] += $n;
                $totals['spaces_published'] += $user->spaces()->where('status', SpaceStatus::Published)->count();
            }

            if ($days !== null) {
                $beyond = SocialPost::query()
                    ->whereIn('user_id', Workspace::memberIds($user))
                    ->where('status', SocialPostStatus::Scheduled)
                    ->where('scheduled_at', '>', UtcDateTime::bind(now()->addDays($days)))
                    ->count();
                if ($beyond > 0) {
                    $totals['beyond_window_workspaces']++;
                    $totals['beyond_window_posts'] += $beyond;
                }
            }

            if ($queue !== null && ($queued = PlanQuota::queuedPosts($user)) > $queue) {
                $totals['over_queue_workspaces']++;
                $totals['over_queue_posts'] += $queued - $queue;
            }

            if ($clients !== null && PlanQuota::approvalClients($user)->count() > $clients) {
                $totals['over_clients_workspaces']++;
            }
        }

        $this->table(['What', 'Count'], [
            ['Workspaces with a plans v2 limit (Free)', $totals['workspaces']],
            ['… with Spaces (kept; create/edit/publish now 402)', $totals['spaces_workspaces']],
            ['   Spaces on them (published)', $totals['spaces'].' ('.$totals['spaces_published'].')'],
            ['… with posts scheduled beyond the window (kept)', $totals['beyond_window_workspaces']],
            ['   Posts beyond the window', $totals['beyond_window_posts']],
            ['… over the queue limit (kept)', $totals['over_queue_workspaces']],
            ['   Posts past the limit', $totals['over_queue_posts']],
            ['… with more approval clients than allowed (links kept)', $totals['over_clients_workspaces']],
        ]);

        return self::SUCCESS;
    }
}
