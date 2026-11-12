<?php

namespace App\Support;

use App\Actions\Auth\RevokeUserAccess;
use App\Enums\SocialAccountStatus;
use App\Enums\SocialPostStatus;
use App\Enums\SocialPostTargetStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\ProfileFontController;
use App\Models\AccountExport;
use App\Models\Feedback;
use App\Models\File;
use App\Models\InsightReport;
use App\Models\InsightShareLink;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPlanLink;
use App\Models\SocialPost;
use App\Models\Space;
use App\Models\SpaceArchive;
use App\Models\TeamMember;
use App\Models\Transfer;
use App\Models\User;
use App\Notifications\AccountDeleted;
use App\Notifications\AccountDeletionCancelled;
use App\Notifications\AccountDeletionScheduled;
use App\Services\Analytics;
use App\Services\CreemService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Account deletion (Play Store requirement).
 *
 * request(): the account goes pending_deletion at once — every way in is
 * revoked, a Creem plan stops renewing (a Play plan is the person's to
 * cancel in Play: we hand back its manage URL), scheduled posts are
 * cancelled and paid back, approval and plan links stop answering, social
 * accounts are disconnected at the relay, team seats are released, and a
 * mail says when the purge happens. Sign-in still works; everything but
 * cancelling answers 423 (EnsureNotPendingDeletion).
 *
 * cancel(): back to active within the grace period. What request() undid
 * outside our rows (the Creem renewal, the relay connections) stays undone.
 *
 * purge(): after GRACE_DAYS, the content goes (rows and R2 objects) and
 * the user row is anonymised and soft-deleted. Billing and the approval
 * audit trail are kept — anonymised. Idempotent: a half-done purge is
 * finished by the next run.
 */
final class AccountDeletion
{
    public const GRACE_DAYS = 30;

    public const CONFIRM = 'DELETE';

    public static function purgeAt(User $user): ?CarbonImmutable
    {
        return $user->deletion_requested_at?->toImmutable()->addDays(self::GRACE_DAYS);
    }

    public static function placeholderEmail(User $user): string
    {
        return 'deleted+'.Str::lower($user->ulid).'@invalid';
    }

    /**
     * @return array{purge_at: string, play_manage_url: string|null}
     */
    public static function request(User $user): array
    {
        $user->refresh();
        if ($user->status !== UserStatus::PendingDeletion) {
            $user->forceFill(['status' => UserStatus::PendingDeletion, 'deletion_requested_at' => now()])->save();
        }

        RevokeUserAccess::run($user);
        $playUrl = self::stopBilling($user);
        self::cancelScheduledPosts($user);
        self::closeLinks($user);
        self::disconnectAccounts($user);
        TeamMember::query()->where('owner_id', $user->id)->orWhere('member_id', $user->id)->delete();
        Workspace::forget();

        $purgeAt = self::purgeAt($user);
        $user->notify(new AccountDeletionScheduled($purgeAt, $playUrl));

        Log::info('account.deletion_requested', ['user' => $user->ulid, 'purge_at' => $purgeAt->utc()->toIso8601String()]);

        return ['purge_at' => $purgeAt->utc()->toIso8601String(), 'play_manage_url' => $playUrl];
    }

    public static function cancel(User $user): void
    {
        $user->forceFill(['status' => UserStatus::Active, 'deletion_requested_at' => null])->save();
        $user->notify(new AccountDeletionCancelled);

        Log::info('account.deletion_cancelled', ['user' => $user->ulid]);
    }

    /** Creem stops renewing; Play is the person's to cancel — its URL comes back. */
    private static function stopBilling(User $user): ?string
    {
        $subscription = $user->activeSubscription();
        if ($subscription === null) {
            return null;
        }
        if ($subscription->isGoogle()) {
            return $subscription->playManageUrl();
        }

        if ($subscription->creem_subscription_id !== null && $subscription->cancelled_at === null) {
            try {
                app(CreemService::class)->cancel($subscription);
            } catch (Throwable $e) {
                report($e);
            }
        }
        $subscription->forceFill(['cancelled_at' => $subscription->cancelled_at ?? now()])->save();

        return null;
    }

    /** Nothing more goes out; what was paid for and not sent comes back. */
    private static function cancelScheduledPosts(User $user): void
    {
        $providers = app(SocialProviders::class);

        SocialPost::query()->where('user_id', $user->id)->where('status', SocialPostStatus::Scheduled)->with('user')
            ->each(function (SocialPost $post) use ($providers): void {
                $sent = $post->provider_post_id !== null;
                if ($sent) {
                    try {
                        $providers->for($post)->deletePost($post->provider_post_id);
                    } catch (Throwable $e) {
                        report($e);
                    }
                }

                DB::transaction(function () use ($post, $sent): void {
                    $post->update(['status' => SocialPostStatus::Cancelled]);
                    if ($sent) {
                        Credits::returnSend(
                            $post,
                            $post->targets()->where('status', SocialPostTargetStatus::Pending)->get(),
                            'Cancelled: account deletion',
                        );
                    }
                });
            });
    }

    /** Approval and plan links stop answering (public endpoints 404). */
    private static function closeLinks(User $user): void
    {
        SocialApprovalLink::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        SocialPlanLink::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    private static function disconnectAccounts(User $user): void
    {
        $providers = app(SocialProviders::class);

        SocialAccount::query()->where('user_id', $user->id)
            ->where('status', '!=', SocialAccountStatus::Disconnected->value)
            ->each(function (SocialAccount $account) use ($providers): void {
                try {
                    $provider = $providers->for($account);
                    $provider->disconnect($account->provider_account_id);
                    $provider->deleteAccount($account->provider_account_id);
                } catch (Throwable $e) {
                    report($e);
                }
                $account->update(['status' => SocialAccountStatus::Disconnected]);
            });
    }

    /**
     * Everything the person made goes; billing and the approval audit trail
     * stay, anonymised. Returns what was removed, for the command's log.
     *
     * @return array<string, int>
     */
    public static function purge(User $user): array
    {
        $email = (string) $user->email;
        $placeholder = self::placeholderEmail($user);
        $counts = [];

        // Told at the address they used, before it is gone from our rows.
        if (! str_ends_with($email, '@invalid')) {
            try {
                Notification::route('mail', $email)->notify(new AccountDeleted);
            } catch (Throwable $e) {
                // An address that bounces must not keep the account forever.
                report($e);
            }
        }

        // Posts (targets, comments, link pivots, file pivots cascade;
        // approval decisions keep their rows with the post set to null).
        $counts['posts'] = SocialPost::query()->where('user_id', $user->id)->delete();
        $counts['approval_links'] = SocialApprovalLink::query()->where('user_id', $user->id)->delete();
        $counts['plan_links'] = SocialPlanLink::query()->where('user_id', $user->id)->delete();
        // Accounts take their inbox (conversations, messages, comments) with them.
        $counts['social_accounts'] = SocialAccount::query()->where('user_id', $user->id)->delete();
        DB::table('social_conversations')->where('user_id', $user->id)->delete();
        DB::table('social_comments')->where('user_id', $user->id)->delete();

        // Spaces before files: their items hold the files.
        $counts['spaces'] = 0;
        Space::withTrashed()->where('user_id', $user->id)->each(function (Space $space) use (&$counts): void {
            SpaceArchive::query()->where('space_id', $space->id)->whereNotNull('path')
                ->each(fn (SpaceArchive $a) => Storage::disk('s3')->delete((string) $a->path));
            $space->forceDelete();
            $counts['spaces']++;
        });
        $counts['transfers'] = Transfer::query()->where('user_id', $user->id)->delete();

        $counts['files'] = 0;
        File::withTrashed()->where('user_id', $user->id)->with('versions')->each(function (File $file) use (&$counts): void {
            FileController::purge($file);
            $counts['files']++;
        });
        DB::table('folders')->where('user_id', $user->id)->delete();
        Storage::disk('s3')->delete(ProfileFontController::path($user));

        // Insight: their reports (rows and the PDFs on R2) and share links,
        // made by them or in their workspace. The user row is only soft
        // deleted, so nothing here goes by the foreign key's cascade.
        $counts['insight_reports'] = 0;
        InsightReport::query()->where('user_id', $user->id)->orWhere('owner_id', $user->id)
            ->each(function (InsightReport $report) use (&$counts): void {
                if ($report->path !== null) {
                    Storage::disk(InsightReport::DISK)->delete($report->path);
                }
                $report->delete();
                $counts['insight_reports']++;
            });
        $counts['insight_share_links'] = InsightShareLink::query()
            ->where('user_id', $user->id)->orWhere('owner_id', $user->id)->delete();
        // Someone else's link shared with them: the link stays the owner's,
        // without their address (it becomes a plain public link).
        $counts['insight_shared_with_anonymised'] = InsightShareLink::query()->where('recipient_email', Str::lower($email))
            ->update(['recipient_email' => null, 'recipient_token' => null]);

        // Their exports (rows and the ZIPs on R2), feedback and AI style.
        $counts['exports'] = 0;
        AccountExport::query()->where('user_id', $user->id)->each(function (AccountExport $export) use (&$counts): void {
            if ($export->path !== null) {
                Storage::disk(AccountExport::DISK)->delete($export->path);
            }
            $export->delete();
            $counts['exports']++;
        });
        $counts['feedback'] = Feedback::query()->where('user_id', $user->id)->delete();
        DB::table('ai_style_profiles')->where('user_id', $user->id)->delete();

        $user->handle()->delete();
        $user->profile()->delete();
        foreach (['notification_preferences', 'activity_log', 'media_upload_flags', 'mcp_audit_logs', 'directory_reviewer_logins'] as $table) {
            DB::table($table)->where('user_id', $user->id)->delete();
        }
        DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->id)->delete();
        TeamMember::query()->where('owner_id', $user->id)->orWhere('member_id', $user->id)->delete();

        // The approval audit trail of other people's links stays — without
        // who they were: address, account, IP and browser. The decision,
        // its time and comment are the owner's record.
        $counts['approval_audit_anonymised'] = DB::table('social_approval_decisions')
            ->where(fn ($q) => $q->where('approver_email', $email)->orWhere('approver_user_id', $user->id))
            ->update(['approver_email' => $placeholder, 'approver_user_id' => null, 'ip' => null, 'user_agent' => null]);
        DB::table('social_approval_links')->where('approver_email', $email)->update(['approver_email' => $placeholder]);
        DB::table('approver_login_tokens')->where('email', $email)->delete();

        app(Analytics::class)->deletePerson($user->ulid);

        $user->forceFill([
            'name' => 'Deleted user',
            'email' => $placeholder,
            'google_id' => null,
            'avatar_url' => null,
            'password' => Str::random(64),
            'remember_token' => null,
            'social_settings' => null,
            'timezone' => null,
            'weekly_digest_sent_on' => null,
            'status' => UserStatus::Deleted,
        ])->save();
        $user->delete();

        Log::info('account.purged', ['user' => $user->ulid] + $counts);

        return $counts;
    }
}
