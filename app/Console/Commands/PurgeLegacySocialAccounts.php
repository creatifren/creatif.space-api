<?php

namespace App\Console\Commands;

use App\Support\SocialProviders;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Remove every social account held by a relay other than SOCIAL_PROVIDER
 * (after the Outstand switch: the Post for Me rows), and everything that
 * only exists because of them. Owner decision 9 Nov: no real users yet.
 *
 * Dry run by default — prints what would go, per table. --force does it
 * in one transaction. Users, subscriptions, invoices, Credits (wallet
 * rows), files and spaces are never touched; neither are the activity log
 * and notifications, which are history.
 *
 *   social_post_targets      delete  targets on a legacy account
 *   social_posts             delete  posts left with no target
 *   social_post_comments     delete  approval comments on those posts
 *   social_approval_link_post delete those posts on approval links
 *   social_approval_links    delete  links left with no post
 *   social_messages          delete  messages in legacy conversations
 *   social_conversations     delete  conversations of legacy accounts
 *   social_comments          delete  comments read on legacy accounts
 *   social_comments          null    social_post_id / private reply thread
 *                                    of other accounts' comments pointing
 *                                    at a deleted post or conversation
 *   social_plan_links        update  legacy ids taken out of account_ids
 *   social_plan_links        delete  links that named only legacy accounts
 *   social_accounts          delete  the legacy accounts themselves
 */
class PurgeLegacySocialAccounts extends Command
{
    protected $signature = 'social:purge-legacy {--force : Delete; without it this is a dry run}';

    protected $description = 'Delete social accounts of a relay other than SOCIAL_PROVIDER, and what depends only on them';

    public function handle(): int
    {
        $current = (string) config('services.social.provider');
        if ($current === '' || ! in_array($current, SocialProviders::NAMES, true)) {
            $this->error('SOCIAL_PROVIDER is empty or unknown ('.json_encode($current).') — refusing: every account would count as legacy.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $plan = $force
            ? DB::transaction(fn () => $this->purge($current))
            : $this->plan($current);

        $this->report($current, $plan, $force);
        Log::info('social.purge_legacy', [
            'provider' => $current,
            'forced' => $force,
            'counts' => array_map(fn (array $r) => $r['rows'], $plan['rows']),
        ]);

        return self::SUCCESS;
    }

    /**
     * What would go. Ids are read once; --force reads them inside its
     * transaction (with the rows locked) and deletes exactly those.
     *
     * @return array{accounts: Collection<int, \stdClass>, rows: array<string, array{table: string, action: string, rows: int, run: callable}>}
     */
    private function plan(string $current): array
    {
        $accountQuery = DB::table('social_accounts')->where('provider', '!=', $current)
            // The reviewer's seeded demo channel is not a legacy account.
            ->where('is_demo', false);
        $accounts = (DB::transactionLevel() > 0 ? $accountQuery->lockForUpdate() : $accountQuery)
            ->orderBy('id')->get(['id', 'user_id', 'provider']);
        $legacy = $accounts->pluck('id')->all();

        $targets = DB::table('social_post_targets')->whereIn('social_account_id', $legacy)->pluck('id')->all();
        $touchedPosts = DB::table('social_post_targets')->whereIn('id', $targets)->distinct()->pluck('social_post_id')->all();
        // A post goes only when every one of its targets is on a legacy account.
        $posts = DB::table('social_posts')->whereIn('id', $touchedPosts)
            ->whereNotExists(fn (Builder $q) => $q->from('social_post_targets as t')
                ->whereColumn('t.social_post_id', 'social_posts.id')
                ->whereNotIn('t.social_account_id', $legacy ?: [0]))
            ->pluck('id')->all();
        $linkRows = DB::table('social_approval_link_post')->whereIn('social_post_id', $posts);
        $links = DB::table('social_approval_links')
            ->whereIn('id', (clone $linkRows)->select('social_approval_link_id'))
            ->whereNotExists(fn (Builder $q) => $q->from('social_approval_link_post as lp')
                ->whereColumn('lp.social_approval_link_id', 'social_approval_links.id')
                ->whereNotIn('lp.social_post_id', $posts ?: [0]))
            ->pluck('id')->all();
        $conversations = DB::table('social_conversations')->whereIn('social_account_id', $legacy)->pluck('id')->all();

        [$planUpdate, $planDelete] = $this->planLinks($legacy);

        $rows = [
            'comments.post_null' => [
                'table' => 'social_comments', 'action' => 'null social_post_id',
                'query' => fn () => DB::table('social_comments')->whereNotIn('social_account_id', $legacy ?: [0])->whereIn('social_post_id', $posts),
                'write' => fn (Builder $q) => $q->update(['social_post_id' => null]),
            ],
            'comments.thread_null' => [
                'table' => 'social_comments', 'action' => 'null private_reply_conversation_id',
                'query' => fn () => DB::table('social_comments')->whereNotIn('social_account_id', $legacy ?: [0])->whereIn('private_reply_conversation_id', $conversations),
                'write' => fn (Builder $q) => $q->update(['private_reply_conversation_id' => null]),
            ],
            'messages' => [
                'table' => 'social_messages', 'action' => 'delete',
                'query' => fn () => DB::table('social_messages')->whereIn('social_conversation_id', $conversations),
            ],
            'comments' => [
                'table' => 'social_comments', 'action' => 'delete',
                'query' => fn () => DB::table('social_comments')->whereIn('social_account_id', $legacy),
            ],
            'conversations' => [
                'table' => 'social_conversations', 'action' => 'delete',
                'query' => fn () => DB::table('social_conversations')->whereIn('id', $conversations),
            ],
            // Replies before the comments they answer (self-referencing FK).
            'post_comment_replies' => [
                'table' => 'social_post_comments', 'action' => 'delete (replies)',
                'query' => fn () => DB::table('social_post_comments')->whereIn('social_post_id', $posts)->whereNotNull('parent_id'),
            ],
            'post_comments' => [
                'table' => 'social_post_comments', 'action' => 'delete',
                'query' => fn () => DB::table('social_post_comments')->whereIn('social_post_id', $posts)->whereNull('parent_id'),
            ],
            'link_posts' => [
                'table' => 'social_approval_link_post', 'action' => 'delete',
                'query' => fn () => DB::table('social_approval_link_post')->whereIn('social_post_id', $posts),
            ],
            'links' => [
                'table' => 'social_approval_links', 'action' => 'delete (left empty)',
                'query' => fn () => DB::table('social_approval_links')->whereIn('id', $links),
            ],
            'targets' => [
                'table' => 'social_post_targets', 'action' => 'delete',
                'query' => fn () => DB::table('social_post_targets')->whereIn('id', $targets),
            ],
            'posts' => [
                'table' => 'social_posts', 'action' => 'delete (left without targets)',
                'query' => fn () => DB::table('social_posts')->whereIn('id', $posts),
            ],
            'plan_links.update' => [
                'table' => 'social_plan_links', 'action' => 'remove ids from account_ids',
                'count' => count($planUpdate),
                'apply' => function () use ($planUpdate): void {
                    foreach ($planUpdate as $id => $ids) {
                        DB::table('social_plan_links')->where('id', $id)->update(['account_ids' => json_encode($ids)]);
                    }
                },
            ],
            'plan_links.delete' => [
                'table' => 'social_plan_links', 'action' => 'delete (named only legacy accounts)',
                'query' => fn () => DB::table('social_plan_links')->whereIn('id', $planDelete),
            ],
            'accounts' => [
                'table' => 'social_accounts', 'action' => 'delete (provider ≠ '.$current.')',
                'query' => fn () => DB::table('social_accounts')->whereIn('id', $legacy),
            ],
        ];

        return [
            'accounts' => $accounts,
            'rows' => array_map(fn (array $r) => [
                'table' => $r['table'],
                'action' => $r['action'],
                'rows' => $r['count'] ?? $r['query']()->count(),
                'run' => $r['apply'] ?? fn () => isset($r['write']) ? $r['write']($r['query']()) : $r['query']()->delete(),
            ], $rows),
        ];
    }

    /**
     * Plan links name accounts by internal id in a JSON list (no FK).
     *
     * @param  list<int>  $legacy
     * @return array{0: array<int, list<int>>, 1: list<int>} ids to rewrite, links to delete
     */
    private function planLinks(array $legacy): array
    {
        $update = [];
        $delete = [];
        foreach (DB::table('social_plan_links')->whereNotNull('account_ids')->get(['id', 'account_ids']) as $link) {
            $ids = array_map('intval', (array) json_decode((string) $link->account_ids, true));
            $kept = array_values(array_diff($ids, $legacy));
            if ($kept === $ids) {
                continue;
            }
            if ($kept === []) {
                $delete[] = $link->id;
            } else {
                $update[$link->id] = $kept;
            }
        }

        return [$update, $delete];
    }

    /**
     * Planned inside the transaction (legacy rows locked), so the counts
     * printed are the rows changed.
     *
     * @return array{accounts: Collection<int, \stdClass>, rows: array<string, array{table: string, action: string, rows: int, run: callable}>}
     */
    private function purge(string $current): array
    {
        $plan = $this->plan($current);
        foreach ($plan['rows'] as $row) {
            if ($row['rows'] > 0) {
                ($row['run'])();
            }
        }

        return $plan;
    }

    /**
     * @param  array{accounts: Collection<int, \stdClass>, rows: array<string, array{table: string, action: string, rows: int, run: callable}>}  $plan
     */
    private function report(string $current, array $plan, bool $force): void
    {
        $accounts = $plan['accounts'];
        $this->line(($force ? 'PURGE' : 'DRY RUN').' — current provider: '.$current);
        $this->line(sprintf(
            'Legacy accounts: %d (%s) · users: %s',
            $accounts->count(),
            $accounts->countBy('provider')->map(fn ($n, $p) => "{$p}: {$n}")->implode(', ') ?: 'none',
            $accounts->pluck('user_id')->unique()->sort()->implode(', ') ?: 'none',
        ));
        $this->table(
            ['Table', 'Action', 'Rows'],
            array_map(fn (array $r) => [$r['table'], $r['action'], $r['rows']], array_values($plan['rows'])),
        );
        $this->line($force
            ? 'Done: all of the above in one transaction.'
            : 'Nothing changed. Run again with --force to delete.');
    }
}
