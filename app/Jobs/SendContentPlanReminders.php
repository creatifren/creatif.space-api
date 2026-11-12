<?php

namespace App\Jobs;

use App\Enums\SocialPostApproval;
use App\Enums\SocialPostStatus;
use App\Enums\UserStatus;
use App\Models\SocialApprovalLink;
use App\Notifications\ContentPlanReminder;
use App\Support\PlanScope;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Content Plan reminders: when a live plan still has posts waiting on the
 * approver and the first of them is scheduled within the next 24 hours,
 * email the approver — once per link (reminded_at), never when the link
 * has reminders off (reminders_enabled).
 *
 * "Waiting" is the same rule as the approver's "Waiting your approval"
 * list (SocialApprovalController::waiting).
 */
class SendContentPlanReminders implements ShouldQueue
{
    use Queueable;

    public const LEAD_HOURS = 24;

    private const BATCH = 200;

    public int $tries = 1;

    public function handle(): void
    {
        $now = CarbonImmutable::now();

        SocialApprovalLink::query()
            ->live()
            ->whereNotNull('scope_from')
            ->whereNotNull('approver_email')
            ->where('reminders_enabled', true)
            ->whereNull('reminded_at')
            // A plan whose last day is over has nothing left to remind about.
            ->where('scope_ends_at', '>', $now)
            ->where('scope_starts_at', '<', $now->addHours(self::LEAD_HOURS))
            ->with('user')
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get()
            ->each(fn (SocialApprovalLink $link) => $this->remind($link, $now));
    }

    private function remind(SocialApprovalLink $link, CarbonImmutable $now): void
    {
        if ($link->user === null || $link->user->status === UserStatus::PendingDeletion) {
            return;
        }

        $waiting = PlanScope::posts($link)
            ->where('status', SocialPostStatus::Scheduled)
            ->whereIn('approval', array_map(fn (SocialPostApproval $a) => $a->value, PlanScope::decidableFrom($link)))
            ->whereNot('approval', SocialPostApproval::Revision->value)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>', $now)
            ->orderBy('scheduled_at')
            ->get(['social_posts.id', 'social_posts.scheduled_at']);

        $first = $waiting->first()?->scheduled_at;
        if ($first === null || CarbonImmutable::parse($first)->gt($now->addHours(self::LEAD_HOURS))) {
            return;
        }

        // Claim it first: two overlapping runs never send two emails.
        $claimed = SocialApprovalLink::query()->whereKey($link->id)->whereNull('reminded_at')->update(['reminded_at' => $now]);
        if ($claimed !== 1) {
            return;
        }

        try {
            Notification::route('mail', $link->approver_email)->notify(new ContentPlanReminder(
                $link, $waiting->count(), CarbonImmutable::parse($first), self::optOutUrl($link),
            ));
        } catch (Throwable $e) {
            // Once means once: a failed send is logged, not retried into a second email.
            Log::warning('content plan reminder failed', ['link' => $link->ulid, 'error' => $e->getMessage()]);
        }
    }

    /** One click, no sign-in: signed, for this link only, valid while the plan runs. */
    public static function optOutUrl(SocialApprovalLink $link): string
    {
        return URL::temporarySignedRoute(
            'api.v1.approval-links.reminders-off',
            ($link->scope_ends_at ?? now())->addDays(30),
            ['link' => $link->ulid],
        );
    }
}
