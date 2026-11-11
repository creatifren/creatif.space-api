<?php

namespace App\Console\Commands;

use App\Enums\SocialPostTargetStatus;
use App\Models\SocialAccount;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Support\PlanQuota;
use App\Support\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Number;

/**
 * Launch-day support: one person's account at a glance, for whoever is
 * answering support@. Read-only.
 *
 *   php artisan support:user someone@example.com
 *
 * Prints the plan (the workspace owner's), the billing platform, connected
 * channels, queued posts, storage used, and the last five publish failures
 * with their person-readable reason and code. Never a token, a secret, a
 * payment id or the relay's raw error text (fail_reason only — the same
 * sentence the user saw).
 */
class SupportUser extends Command
{
    protected $signature = 'support:user {email : The account\'s email address}';

    protected $description = 'Show one account\'s plan, platform, channels, queue, storage and last publish failures (read-only)';

    public const FAILURES = 5;

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user === null) {
            $this->error("No account with the email {$email}.");

            return self::FAILURE;
        }

        $owner = Workspace::owner($user);
        $plan = $owner->plan();
        $subscription = $owner->activeSubscription();
        $members = Workspace::memberIds($owner);

        $accounts = SocialAccount::query()->whereIn('user_id', $members)->get();
        $usable = $accounts->filter(fn (SocialAccount $a) => $a->isUsable());

        $used = PlanQuota::storageUsed($owner);
        $limit = PlanQuota::storageLimit($owner);

        $this->line("<options=bold>{$user->email}</> ({$user->ulid})");
        $this->table(['', ''], [
            ['Workspace', $owner->is($user) ? 'own' : "member of {$owner->email} (".Workspace::role($user).')'],
            ['Plan', $plan->name.' ('.$plan->key->value.')'.($subscription !== null
                ? ' — '.$subscription->status->value.', '.$subscription->billing_period->value
                    .($subscription->current_period_end !== null ? ', period ends '.$subscription->current_period_end->toDateString() : '')
                : ' — no subscription')],
            ['Platform', $this->platform($subscription?->provider)],
            ['Channels', $usable->count().' connected'
                .($accounts->count() > $usable->count() ? ', '.($accounts->count() - $usable->count()).' need reconnecting' : '')
                .($usable->isNotEmpty() ? ' ('.$usable->map(fn (SocialAccount $a) => $a->label())->implode(', ').')' : '')],
            ['Queued posts', PlanQuota::queuedPosts($owner).' (limit '.(PlanQuota::queueLimit($owner) ?? 'none').')'],
            ['Storage', Number::fileSize($used, 1).' of '.($limit === null ? 'unlimited' : Number::fileSize($limit, 1))],
            ['Account status', $user->status->value.($user->suspended_at !== null ? ' since '.$user->suspended_at->toDateString() : '')],
        ]);

        $failures = SocialPostTarget::query()
            ->with(['post:id,ulid,user_id', 'account:id,platform,username'])
            ->where('status', SocialPostTargetStatus::Failed->value)
            ->whereHas('post', fn ($q) => $q->whereIn('user_id', $members))
            ->latest('updated_at')
            ->latest('id')
            ->limit(self::FAILURES)
            ->get();

        $this->line('<options=bold>Last '.self::FAILURES.' publish failures</>');

        if ($failures->isEmpty()) {
            $this->line('None.');

            return self::SUCCESS;
        }

        $this->table(['When (UTC)', 'Post', 'Channel', 'Code', 'Reason'], $failures->map(fn (SocialPostTarget $t) => [
            $t->updated_at?->utc()->format('Y-m-d H:i'),
            $t->post?->ulid,
            $t->account?->label() ?? '(removed)',
            $t->fail_code ?? '—',
            $t->fail_reason ?? '—',
        ])->all());

        return self::SUCCESS;
    }

    /** Where the plan is billed: the Android app (Google Play) or the web (Creem). */
    private function platform(?string $provider): string
    {
        return match ($provider) {
            'google' => 'android (Google Play billing)',
            'creem' => 'web (Creem billing)',
            null => 'none (no paid plan)',
            default => $provider,
        };
    }
}
