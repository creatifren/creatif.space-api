<?php

namespace App\Console\Commands;

use App\Models\PublishAttempt;
use App\Models\SocialApprovalDecision;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Support\ApproverGate;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Launch-day support: one post's story, oldest first. Read-only.
 *
 *   php artisan support:post 01JD…        (the post id)
 *   php artisan support:post op_abc123    (an Outstand post id works too)
 *
 * Prints the post (owner, status, approval, relay, Outstand ids), then a
 * timeline: drafted, sent for approval, every approval decision (approver
 * masked, source), every publish attempt with its failure code and reason,
 * and each channel's state now. Never a token, a secret, the approver's
 * full address or IP, or the relay's raw error text (the reason only: the
 * sentence the user saw).
 */
class SupportPost extends Command
{
    protected $signature = 'support:post {id : The post id (ULID), or an Outstand post id}';

    protected $description = 'Show one post\'s timeline: drafted, approval events, publish attempts with failure codes, Outstand ids (read-only)';

    public function handle(): int
    {
        $id = trim((string) $this->argument('id'));
        $post = self::find($id);

        if ($post === null) {
            $this->error("No post with the id {$id}.");

            return self::FAILURE;
        }

        $post->load(['user:id,ulid,email', 'targets.account:id,platform,username']);

        $this->line("<options=bold>Post {$post->ulid}</>");
        $this->table(['', ''], [
            ['Owner', ($post->user->email ?? '(deleted)').' ('.($post->user->ulid ?? '—').')'],
            ['Caption', Str::of($post->caption)->squish()->limit(60)->toString()],
            ['Status', $post->status->value.($post->fail_reason ? ' — '.$post->fail_reason : '')],
            ['Approval', $post->approval->value ?? 'none'],
            ['Scheduled (UTC)', self::utc($post->scheduled_at) ?? 'now / none'],
            ['Relay', $post->provider],
            ['Outstand post id', $post->provider_post_id ?? '—'],
        ]);

        $this->line('<options=bold>Timeline (UTC)</>');
        $this->table(['When', 'What', 'Detail'], self::timeline($post));

        $this->line('<options=bold>Channels now</>');
        $this->table(['Channel', 'Status', 'Code', 'Reason', 'Retries', 'Outstand ids'], $post->targets->map(fn (SocialPostTarget $t) => [
            $t->account?->label() ?? '(removed)',
            $t->status->value,
            $t->fail_code ?? '—',
            $t->fail_reason ?? '—',
            (string) $t->attempts,
            collect(['retry' => $t->retry_provider_post_id, 'result' => $t->provider_result_id])->filter()->map(fn ($v, $k) => "{$k} {$v}")->implode(', ') ?: '—',
        ])->all());

        return self::SUCCESS;
    }

    private static function find(string $id): ?SocialPost
    {
        return SocialPost::query()->where('ulid', $id)->first()
            ?? SocialPost::query()->where('provider_post_id', $id)->first()
            ?? SocialPostTarget::query()->where('retry_provider_post_id', $id)->first()?->post;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function timeline(SocialPost $post): array
    {
        $rows = [[$post->created_at, 0, 'drafted', '']];

        if ($post->review_requested_at !== null) {
            $rows[] = [$post->review_requested_at, 1, 'sent for approval', ''];
        }

        $decisions = SocialApprovalDecision::query()
            ->where('social_post_id', $post->id)
            ->with('link:id,ulid,title')
            ->orderBy('created_at')->orderBy('id')
            ->get();
        foreach ($decisions as $d) {
            $rows[] = [$d->created_at, 2, 'approval: '.$d->decision, collect([
                $d->approver_email ? 'by '.ApproverGate::hint($d->approver_email) : ($d->client_name ? 'by '.$d->client_name : null),
                'via '.$d->source,
                $d->link ? 'link '.$d->link->ulid : null,
                $d->comment ? '“'.Str::of($d->comment)->squish()->limit(80).'”' : null,
            ])->filter()->implode(' · ')];
        }

        $labels = $post->targets->mapWithKeys(fn (SocialPostTarget $t) => [$t->id => $t->account?->label() ?? '(removed)']);
        $attempts = PublishAttempt::query()->where('social_post_id', $post->id)->orderBy('created_at')->orderBy('id')->get();
        foreach ($attempts as $a) {
            $rows[] = [$a->created_at, 3, "publish #{$a->attempt}: {$a->outcome}", collect([
                $labels[$a->social_post_target_id] ?? null,
                $a->failure_code,
                $a->reason,
                $a->provider_post_id ? 'Outstand '.$a->provider_post_id : null,
                $a->source,
            ])->filter()->implode(' · ')];
        }

        usort($rows, fn ($x, $y) => [$x[0]?->getTimestamp() ?? 0, $x[1]] <=> [$y[0]?->getTimestamp() ?? 0, $y[1]]);

        return array_map(fn ($r) => [self::utc($r[0]) ?? '—', $r[2], $r[3]], $rows);
    }

    private static function utc(?CarbonInterface $at): ?string
    {
        return $at?->copy()->utc()->format('Y-m-d H:i:s');
    }
}
