<?php

use App\Models\Invoice;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialComment;
use App\Models\SocialConversation;
use App\Models\SocialMessage;
use App\Models\SocialPlanLink;
use App\Models\SocialPost;
use App\Models\SocialPostComment;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Credits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * social:purge-legacy — the Post for Me rows left after the Outstand
 * switch, and what only exists because of them. Runs on sqlite and on the
 * MySQL CI leg.
 */

beforeEach(function () {
    config(['services.social.provider' => 'outstand']);
});

function purgeAccount(User $user, string $provider, string $platform = 'instagram'): SocialAccount
{
    return SocialAccount::factory()->for($user)->create(['provider' => $provider, 'platform' => $platform]);
}

function purgePost(User $user, string $provider, SocialAccount ...$accounts): SocialPost
{
    $post = SocialPost::factory()->for($user)->create(['provider' => $provider, 'provider_post_id' => null]);
    foreach ($accounts as $a) {
        $post->targets()->create(['social_account_id' => $a->id, 'status' => 'pending']);
    }

    return $post;
}

function purgeThread(SocialAccount $a, string $id): SocialConversation
{
    $c = SocialConversation::query()->create([
        'user_id' => $a->user_id, 'social_account_id' => $a->id, 'provider' => $a->provider,
        'provider_conversation_id' => $id, 'network' => 'instagram', 'contact_id' => 'c_'.$id,
        'last_message_at' => now(), 'status' => 'open',
    ]);
    SocialMessage::query()->create([
        'social_conversation_id' => $c->id, 'provider_message_id' => 'm_'.$id, 'direction' => 'in',
        'content' => 'hi', 'status' => 'sent', 'source' => 'api', 'sent_at' => now(),
    ]);

    return $c;
}

function purgeComment(SocialAccount $a, string $id, array $attrs = []): SocialComment
{
    return SocialComment::query()->create($attrs + [
        'user_id' => $a->user_id, 'social_account_id' => $a->id, 'provider' => $a->provider,
        'provider_post_id' => 'pp_'.$id, 'comment_id' => 'cm_'.$id, 'author_id' => 'fan',
        'text' => 'nice', 'network' => 'instagram', 'commented_at' => now(),
    ]);
}

/** Two owners: one with only Post for Me rows, one with both. */
function purgeWorld(): array
{
    $old = User::factory()->create();
    $mixed = User::factory()->create();
    $sub = Subscription::factory()->for($mixed)->create();
    $sub->invoices()->create(['number' => Invoice::nextNumber(), 'amount' => 999, 'currency' => 'USD', 'status' => 'paid']);
    Credits::grant($mixed, 5000, 'Test');

    $legacyA = purgeAccount($old, 'postforme');
    $legacyB = purgeAccount($mixed, 'postforme', 'facebook');
    $current = purgeAccount($mixed, 'outstand', 'tiktok');

    $onlyLegacy = purgePost($old, 'postforme', $legacyA);
    $both = purgePost($mixed, 'postforme', $legacyB, $current);
    $currentOnly = purgePost($mixed, 'outstand', $current);

    $root = SocialPostComment::query()->create(['social_post_id' => $onlyLegacy->id, 'author_name' => 'Andi', 'role' => 'client', 'body' => 'ok?']);
    SocialPostComment::query()->create(['social_post_id' => $onlyLegacy->id, 'parent_id' => $root->id, 'author_name' => 'Rani', 'role' => 'editor', 'body' => 'yes']);
    SocialPostComment::query()->create(['social_post_id' => $currentOnly->id, 'author_name' => 'Andi', 'role' => 'client', 'body' => 'keep']);

    $legacyLink = SocialApprovalLink::query()->create(['user_id' => $old->id, 'title' => 'Old', 'client_name' => 'Andi']);
    $legacyLink->posts()->attach($onlyLegacy->id);
    $mixedLink = SocialApprovalLink::query()->create(['user_id' => $mixed->id, 'title' => 'Mixed', 'client_name' => 'Andi']);
    $mixedLink->posts()->attach([$both->id, $currentOnly->id]);

    $legacyThread = purgeThread($legacyA, 'old');
    $currentThread = purgeThread($current, 'new');
    purgeComment($legacyA, 'old', ['social_post_id' => $onlyLegacy->id]);
    $kept = purgeComment($current, 'new', ['private_reply_conversation_id' => $legacyThread->id]);

    $plan = fn (User $u, ?array $ids) => SocialPlanLink::query()->create([
        'user_id' => $u->id, 'token' => str()->random(40), 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'account_ids' => $ids,
    ]);
    $planOld = $plan($old, [$legacyA->id]);
    $planMixed = $plan($mixed, [$legacyB->id, $current->id]);
    $planAll = $plan($mixed, null);

    return compact('old', 'mixed', 'legacyA', 'legacyB', 'current', 'onlyLegacy', 'both', 'currentOnly',
        'legacyLink', 'mixedLink', 'legacyThread', 'currentThread', 'kept', 'planOld', 'planMixed', 'planAll');
}

/** Row counts of every table the purge may touch, and of those it must not. */
function purgeCounts(): array
{
    return collect([
        'social_accounts', 'social_post_targets', 'social_posts', 'social_post_comments', 'social_approval_link_post',
        'social_approval_links', 'social_conversations', 'social_messages', 'social_comments', 'social_plan_links',
        'users', 'subscriptions', 'invoices', 'wallet_transactions', 'files', 'spaces',
    ])->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
}

it('changes nothing on a dry run and prints per-table counts', function () {
    $w = purgeWorld();
    $before = purgeCounts();

    $this->artisan('social:purge-legacy')
        ->expectsOutputToContain('DRY RUN — current provider: outstand')
        ->expectsOutputToContain('Legacy accounts: 2 (postforme: 2) · users: '.collect([$w['old']->id, $w['mixed']->id])->sort()->implode(', '))
        ->expectsTable(['Table', 'Action', 'Rows'], [
            ['social_comments', 'null social_post_id', 0],
            ['social_comments', 'null private_reply_conversation_id', 1],
            ['social_messages', 'delete', 1],
            ['social_comments', 'delete', 1],
            ['social_conversations', 'delete', 1],
            ['social_post_comments', 'delete (replies)', 1],
            ['social_post_comments', 'delete', 1],
            ['social_approval_link_post', 'delete', 1],
            ['social_approval_links', 'delete (left empty)', 1],
            ['social_post_targets', 'delete', 2],
            ['social_posts', 'delete (left without targets)', 1],
            ['social_plan_links', 'remove ids from account_ids', 1],
            ['social_plan_links', 'delete (named only legacy accounts)', 1],
            ['social_accounts', 'delete (provider ≠ outstand)', 2],
        ])
        ->expectsOutputToContain('Nothing changed. Run again with --force to delete.')
        ->assertSuccessful();

    expect(purgeCounts())->toBe($before);
});

it('deletes only legacy rows and what depends only on them', function () {
    Log::spy();
    $w = purgeWorld();
    $before = purgeCounts();

    $this->artisan('social:purge-legacy --force')
        ->expectsOutputToContain('PURGE — current provider: outstand')
        ->expectsOutputToContain('Done: all of the above in one transaction.')
        ->assertSuccessful();

    // Gone.
    expect(SocialAccount::query()->whereIn('id', [$w['legacyA']->id, $w['legacyB']->id])->exists())->toBeFalse()
        ->and(SocialPost::query()->find($w['onlyLegacy']->id))->toBeNull()
        ->and(SocialApprovalLink::query()->find($w['legacyLink']->id))->toBeNull()
        ->and(SocialConversation::query()->find($w['legacyThread']->id))->toBeNull()
        ->and(SocialPlanLink::query()->find($w['planOld']->id))->toBeNull()
        ->and(SocialComment::query()->where('social_account_id', $w['legacyA']->id)->exists())->toBeFalse();

    // Kept, and cut loose from what went.
    expect($w['current']->fresh())->not->toBeNull()
        ->and($w['both']->fresh()->targets()->pluck('social_account_id')->all())->toBe([$w['current']->id])
        ->and($w['currentOnly']->fresh()->targets()->count())->toBe(1)
        ->and($w['mixedLink']->fresh()->posts()->pluck('social_posts.id')->sort()->values()->all())
        ->toBe(collect([$w['both']->id, $w['currentOnly']->id])->sort()->values()->all())
        ->and($w['currentThread']->fresh())->not->toBeNull()
        ->and($w['currentThread']->messages()->count())->toBe(1)
        ->and($w['kept']->fresh()->private_reply_conversation_id)->toBeNull()
        ->and($w['planMixed']->fresh()->account_ids)->toBe([$w['current']->id])
        ->and($w['planAll']->fresh()->account_ids)->toBeNull()
        ->and(SocialPostComment::query()->where('social_post_id', $w['currentOnly']->id)->count())->toBe(1);

    // Never touched.
    $after = purgeCounts();
    foreach (['users', 'subscriptions', 'invoices', 'wallet_transactions', 'files', 'spaces'] as $t) {
        expect($after[$t])->toBe($before[$t]);
    }
    expect(Credits::balance($w['mixed']))->toBe(5000);

    Log::shouldHaveReceived('info')->withArgs(fn ($m, $c) => $m === 'social.purge_legacy'
        && $c['forced'] === true && $c['counts']['accounts'] === 2 && $c['counts']['posts'] === 1)->once();
});

it('finds nothing the second time', function () {
    purgeWorld();
    $this->artisan('social:purge-legacy --force')->assertSuccessful();
    $after = purgeCounts();

    $this->artisan('social:purge-legacy --force')
        ->expectsOutputToContain('Legacy accounts: 0 (none) · users: none')
        ->assertSuccessful();

    expect(purgeCounts())->toBe($after);
});

it('refuses when SOCIAL_PROVIDER is empty or unknown', function (?string $provider) {
    purgeWorld();
    config(['services.social.provider' => $provider]);
    $before = purgeCounts();

    $this->artisan('social:purge-legacy --force')
        ->expectsOutputToContain('refusing')
        ->assertFailed();

    expect(purgeCounts())->toBe($before);
})->with(['empty' => '', 'null' => null, 'unknown' => 'buffer']);

it('rolls everything back when a step fails', function () {
    purgeWorld();
    $before = purgeCounts();
    // A failing write half-way through (the plan-link rewrite).
    DB::listen(function ($q) {
        if (str_contains($q->sql, 'update') && str_contains($q->sql, 'social_plan_links')) {
            throw new RuntimeException('boom');
        }
    });

    expect(fn () => $this->artisan('social:purge-legacy --force')->run())->toThrow(RuntimeException::class);
    expect(purgeCounts())->toBe($before);
});
