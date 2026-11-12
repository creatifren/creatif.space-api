<?php

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Http\Controllers\Api\V1\NotificationSettingsController;
use App\Models\AccountExport;
use App\Models\File;
use App\Models\InsightShareLink;
use App\Models\SocialAccount;
use App\Models\SocialApprovalDecision;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\AccountExportReady;
use App\Support\PlanScope;
use App\Support\Workspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Builds one account data export as a ZIP of JSON files and emails the
 * owner a 24-hour signed link.
 *
 * Every field is picked by hand (allow-list), never toArray(): no
 * passwords, OAuth or API tokens, provider ids or raw provider payloads,
 * approver IPs or user agents. Only rows of the requester's own workspace
 * (Workspace::memberIds of their owner) are read.
 */
class BuildAccountExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $exportId) {}

    public function handle(): void
    {
        $export = AccountExport::query()->with('user')->find($this->exportId);
        if ($export === null || $export->status !== AccountExport::STATUS_QUEUED || $export->user === null) {
            return;
        }
        $export->update(['status' => AccountExport::STATUS_BUILDING]);

        $tmp = tempnam(sys_get_temp_dir(), 'export');
        try {
            $user = $export->user;
            $files = $this->contents($user);

            $zip = new ZipArchive;
            if ($tmp === false || $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not open the ZIP.');
            }
            foreach ($files as $name => $data) {
                $zip->addFromString($name, is_string($data) ? $data : (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            $zip->close();

            $path = "exports/{$user->ulid}/{$export->ulid}.zip";
            $stream = fopen($tmp, 'r');
            Storage::disk(AccountExport::DISK)->put($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            $export->update([
                'status' => AccountExport::STATUS_DONE,
                'path' => $path,
                'size_bytes' => (int) filesize($tmp),
                'completed_at' => now(),
                'expires_at' => now()->addHours(AccountExport::LINK_TTL_HOURS),
            ]);
            $user->notify(new AccountExportReady($export->fresh() ?? $export));
        } catch (Throwable $e) {
            $export->update(['status' => AccountExport::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 500)]);
            Log::warning('account export failed', ['export' => $export->ulid, 'error' => $e->getMessage()]);
        } finally {
            if (is_string($tmp) && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * The ZIP's files: name => JSON-able data (or plain text).
     *
     * @return array<string, mixed>
     */
    public function contents(User $user): array
    {
        $owner = Workspace::owner($user);
        $ids = Workspace::memberIds($owner)->all();
        $at = fn ($d) => $d?->toIso8601String();

        $accounts = SocialAccount::query()->whereIn('user_id', $ids)->orderBy('id')->get();
        $accountUlid = $accounts->pluck('ulid', 'id');

        $posts = SocialPost::query()->whereIn('user_id', $ids)->with('targets', 'user:id,ulid,name')->orderBy('id')->get();
        $postUlid = $posts->pluck('ulid', 'id');

        $links = SocialApprovalLink::query()->whereIn('user_id', $ids)->orderBy('id')->get();
        $decisions = SocialApprovalDecision::query()->whereIn('social_approval_link_id', $links->pluck('id'))->orderBy('id')->get()->groupBy('social_approval_link_id');

        // Decisions this person made as an approver on other people's links:
        // their own data, without where from (no IP or browser).
        $mine = SocialApprovalDecision::query()
            ->where(fn ($q) => $q->where('approver_user_id', $user->id)->orWhere('approver_email', $user->email))
            ->whereNotIn('social_approval_link_id', $links->pluck('id'))
            ->with('link:id,ulid,title')
            ->orderBy('id')->get();

        $shares = InsightShareLink::query()->where('owner_id', $owner->id)->orderBy('id')->get();

        $library = File::query()->whereIn('user_id', $ids)->where('status', File::STATUS_READY)->orderBy('id')->get();
        $fileExpiry = now()->addDays(AccountExport::FILE_URL_TTL_DAYS);

        return [
            'README.txt' => $this->readme($user),
            'account.json' => [
                'id' => $user->ulid,
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'email_verified_at' => $at($user->email_verified_at),
                'created_at' => $at($user->created_at),
            ],
            'workspace.json' => [
                'owner' => ['id' => $owner->ulid, 'name' => $owner->name],
                'you_are' => $owner->is($user) ? 'owner' : 'member',
                'team' => TeamMember::query()->where('owner_id', $owner->id)->orderBy('id')->get()->map(fn (TeamMember $m) => [
                    'email' => $m->email,
                    'role' => $m->role->value,
                    'status' => $m->status->value,
                    'joined_at' => $at($m->joined_at),
                ])->values()->all(),
            ],
            'channels.json' => $accounts->map(fn (SocialAccount $a) => [
                'id' => $a->ulid,
                'platform' => $a->platform->value,
                'username' => $a->username,
                'status' => $a->status->value,
                'followers_count' => $a->followers_count,
                'connected_at' => $at($a->connected_at),
            ])->values()->all(),
            'posts.json' => $posts->map(fn (SocialPost $p) => [
                'id' => $p->ulid,
                'author' => $p->user?->name,
                'caption' => $p->caption,
                'type' => $p->type,
                'status' => $p->status->value,
                'approval' => $p->approval?->value,
                'labels' => $p->labels ?? [],
                'media' => collect($p->media ?? [])->map(fn ($m) => ['url' => $m['url']])->values()->all(),
                'scheduled_at' => $at($p->scheduled_at),
                'created_at' => $at($p->created_at),
                'channels' => $p->targets->map(fn (SocialPostTarget $t) => [
                    'channel_id' => $accountUlid[$t->social_account_id] ?? null,
                    'status' => $t->status->value,
                    'url' => $t->platform_url,
                ])->values()->all(),
            ])->values()->all(),
            'approvals.json' => $links->map(fn (SocialApprovalLink $l) => [
                'id' => $l->ulid,
                'title' => $l->title,
                'client_name' => $l->client_name,
                'approver_email' => $l->approver_email,
                'kind' => $l->isScoped() ? 'plan' : 'list',
                'scope' => PlanScope::toArray($l),
                'status' => $l->status(),
                'created_at' => $at($l->created_at),
                'expires_at' => $at($l->expires_at),
                'revoked_at' => $at($l->revoked_at),
                // The audit, as the audit CSV gives it: who, what, when — not where from.
                'audit' => ($decisions[$l->id] ?? collect())->map(fn (SocialApprovalDecision $d) => [
                    'decided_at' => $at($d->created_at),
                    'approver_name' => $d->client_name,
                    'approver_email' => $d->approver_email,
                    'decision' => $d->decision,
                    'comment' => $d->comment,
                    'post_id' => $postUlid[$d->social_post_id] ?? null,
                    'source' => $d->source,
                ])->values()->all(),
            ])->values()->all(),
            'insight_share_links.json' => $shares->map(fn (InsightShareLink $s) => [
                'id' => $s->ulid,
                'period' => ['from' => $s->period_from->toDateString(), 'to' => $s->period_to->toDateString(), 'kind' => $s->period_kind],
                'channels' => $s->channel_ids,
                'recipient_email' => $s->recipient_email,
                'status' => $s->revoked() ? 'revoked' : ($s->expired() ? 'expired' : 'live'),
                'created_at' => $at($s->created_at),
                'expires_at' => $at($s->expires_at),
                'revoked_at' => $at($s->revoked_at),
            ])->values()->all(),
            'notification_settings.json' => [
                // The emails you get (GET /me/notification-settings) and the digest's timezone.
                'settings' => NotificationSettingsController::settings($user),
                // Every switch on Settings → Notifications, email and bell.
                'preferences' => collect(NotificationType::switchable())->map(fn (NotificationType $t) => [
                    'type' => $t->value,
                    'email' => $user->wants($t, 'email'),
                    'bell' => $user->wants($t, 'bell'),
                ])->values()->all(),
            ],
            'approval_audit.json' => [
                // Every decision on the workspace's links, one row each (also inside approvals.json).
                'on_your_links' => $links->flatMap(fn (SocialApprovalLink $l) => ($decisions[$l->id] ?? collect())->map(fn (SocialApprovalDecision $d) => [
                    'link_id' => $l->ulid,
                    'link_title' => $l->title,
                    'decided_at' => $at($d->created_at),
                    'approver_name' => $d->client_name,
                    'approver_email' => $d->approver_email,
                    'decision' => $d->decision,
                    'comment' => $d->comment,
                    'post_id' => $postUlid[$d->social_post_id] ?? null,
                    'source' => $d->source,
                ]))->values()->all(),
                // What you decided on links others sent you.
                'your_decisions' => $mine->map(fn (SocialApprovalDecision $d) => [
                    'link_id' => $d->link?->ulid,
                    'link_title' => $d->link?->title,
                    'decided_at' => $at($d->created_at),
                    'decision' => $d->decision,
                    'comment' => $d->comment,
                    'source' => $d->source,
                ])->values()->all(),
            ],
            'files.json' => [
                'urls_expire_at' => $fileExpiry->toIso8601String(),
                'files' => $library->map(fn (File $f) => [
                    'id' => $f->ulid,
                    'name' => $f->name,
                    'mime_type' => $f->mime_type,
                    'size_bytes' => $f->size_bytes,
                    'created_at' => $at($f->created_at),
                    'url' => Storage::disk($f->disk)->temporaryUrl($f->path, $fileExpiry),
                ])->values()->all(),
            ],
        ];
    }

    private function readme(User $user): string
    {
        return implode("\n", [
            'creatif.space account export',
            'For: '.$user->email.' — made '.now()->toIso8601String(),
            '',
            'account.json    your account',
            'workspace.json  the workspace you belong to and its team',
            'channels.json   connected social channels (no access tokens)',
            'posts.json      every post in the workspace',
            'approvals.json  approval links and Content Plans, with their decision audit',
            'approval_audit.json  every approval decision as one list, and your own decisions on links sent to you',
            'insight_share_links.json  shared Insight report links (no link tokens)',
            'notification_settings.json  which emails and notifications you get',
            'files.json      library files, each with a download link valid '.AccountExport::FILE_URL_TTL_DAYS.' days',
            '',
            'Passwords, API keys, OAuth tokens and connected-app secrets are never exported.',
            '',
        ]);
    }
}
