<?php

use App\Models\File;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialApprovalDecision;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\TeamMember;
use App\Models\User;
use App\Support\Credits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * Content Plan (Meeting #02): an approval link scoped to a date range
 * (owner's timezone) and optional channels, showing every workspace post in
 * it — including later ones — with the #54 approver rules unchanged.
 */

beforeEach(function () {
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'social_posts_monthly' => 0, 'credits_monthly' => 0]]);
    }
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
    ]);
    Http::fake(['api.postforme.test/*' => fn () => Http::response(['id' => 'post_'.Str::random(10), 'status' => 'scheduled'])]);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 03:00:00', 'UTC'));
});

function cpOwner(bool $paid = true): User
{
    $owner = User::factory()->create();
    if ($paid) {
        Subscription::factory()->for($owner)->create();
    }
    Credits::grant($owner, 50_000, 'Test');

    return User::query()->findOrFail($owner->id);
}

function cpAccount(User $owner, string $platform = 'instagram'): SocialAccount
{
    return SocialAccount::factory()->for($owner)->create(['platform' => $platform, 'username' => $platform.'_brand']);
}

/** A post at an instant (UTC), on one account. */
function cpPost(User $by, SocialAccount $account, string $utc, array $attrs = []): SocialPost
{
    $post = SocialPost::factory()->for($by)->create($attrs + [
        'provider_post_id' => null,
        'status' => 'scheduled',
        'approval' => 'client',
        'scheduled_at' => CarbonImmutable::parse($utc, 'UTC'),
        'caption' => 'Post at '.$utc,
    ]);
    $post->targets()->create(['social_account_id' => $account->id, 'status' => 'pending']);

    return $post;
}

function cpPlan($test, User $owner, array $body = []): array
{
    return $test->actingAs($owner)->postJson('/api/v1/social-approval-links', $body + [
        'date_from' => '2026-12-01',
        'date_to' => '2026-12-31',
        'timezone' => 'Asia/Jakarta',
        'approver_email' => 'rani@client.test',
        'title' => 'December',
    ])->assertCreated()->json('data');
}

function cpToken(array $plan): string
{
    return basename((string) $plan['url']);
}

function cpIds($test, string $token, string $query = ''): array
{
    app('auth')->forgetGuards();

    return collect($test->getJson("/api/v1/a/{$token}{$query}")->assertOk()->json('data.posts'))->pluck('id')->all();
}

describe('scope', function () {
    it('takes calendar days in the owner timezone, stored as UTC bounds — edges included and excluded', function () {
        $owner = cpOwner();
        $ig = cpAccount($owner);

        // Asia/Jakarta is UTC+7: 1 Dec 00:00 WIB = 30 Nov 17:00 UTC; 31 Dec 24:00 WIB = 31 Dec 17:00 UTC.
        $before = cpPost($owner, $ig, '2026-11-30 16:59:00'); // 30 Nov 23:59 WIB — out
        $first = cpPost($owner, $ig, '2026-11-30 17:00:00');  // 1 Dec 00:00 WIB — in
        $last = cpPost($owner, $ig, '2026-12-31 16:59:00');   // 31 Dec 23:59 WIB — in
        $after = cpPost($owner, $ig, '2026-12-31 17:00:00');  // 1 Jan 00:00 WIB — out

        $plan = cpPlan($this, $owner);
        expect($plan['kind'])->toBe('plan')
            ->and($plan['scope'])->toMatchArray([
                'date_from' => '2026-12-01', 'date_to' => '2026-12-31', 'timezone' => 'Asia/Jakarta',
                'starts_at' => '2026-11-30T17:00:00Z', 'ends_at' => '2026-12-31T17:00:00Z', 'channel_ids' => null,
            ])
            ->and($plan['posts'])->toBe(2);

        $ids = cpIds($this, cpToken($plan));
        expect($ids)->toBe([$first->ulid, $last->ulid])
            ->not->toContain($before->ulid)
            ->not->toContain($after->ulid);

        $data = $this->getJson('/api/v1/a/'.cpToken($plan))->json('data');
        expect($data['posts'][0]['local_date'])->toBe('2026-12-01')
            ->and($data['posts'][1]['local_date'])->toBe('2026-12-31');
    });

    it('defaults to the app timezone when none is given', function () {
        $owner = cpOwner();
        $plan = cpPlan($this, $owner, ['timezone' => null]);

        expect($plan['scope']['timezone'])->toBe(config('app.timezone'));
    });

    it('narrows to the scoped channels, and filters by channel and dates inside the scope only', function () {
        $owner = cpOwner();
        $ig = cpAccount($owner, 'instagram');
        $tt = cpAccount($owner, 'tiktok');
        $li = cpAccount($owner, 'linkedin');

        $a = cpPost($owner, $ig, '2026-12-05 03:00:00');
        $b = cpPost($owner, $tt, '2026-12-20 03:00:00');
        $c = cpPost($owner, $li, '2026-12-10 03:00:00'); // not in the scope's channels

        $plan = cpPlan($this, $owner, ['channel_ids' => [$ig->ulid, $tt->ulid]]);
        $token = cpToken($plan);

        expect($plan['scope']['channel_ids'])->toEqualCanonicalizing([$ig->ulid, $tt->ulid])
            ->and(cpIds($this, $token))->toBe([$a->ulid, $b->ulid])
            ->and(cpIds($this, $token, "?channel={$tt->ulid}"))->toBe([$b->ulid])
            // A channel outside the scope widens nothing.
            ->and(cpIds($this, $token, "?channel={$li->ulid}"))->toBe([])
            ->and(cpIds($this, $token, '?from=2026-12-15&to=2026-12-31'))->toBe([$b->ulid])
            // A filter wider than the scope stays inside it.
            ->and(cpIds($this, $token, '?from=2026-01-01&to=2027-12-31'))->toBe([$a->ulid, $b->ulid])
            ->and($c->ulid)->not->toBeIn(cpIds($this, $token));

        $channels = $this->getJson("/api/v1/a/{$token}")->json('data.channels');
        expect(collect($channels)->pluck('id')->all())->toEqualCanonicalizing([$ig->ulid, $tt->ulid]);
    });

    it('shows scheduled / held / approved / changes-requested / published posts, drafts only when marked for review', function () {
        $owner = cpOwner();
        $ig = cpAccount($owner);
        $waiting = cpPost($owner, $ig, '2026-12-02 03:00:00', ['approval' => 'client']);
        $held = cpPost($owner, $ig, '2026-12-03 03:00:00', ['approval' => 'awaiting']);
        $changes = cpPost($owner, $ig, '2026-12-04 03:00:00', ['approval' => 'revision']);
        $approved = cpPost($owner, $ig, '2026-12-05 03:00:00', ['approval' => 'approved']);
        $scheduled = cpPost($owner, $ig, '2026-12-06 03:00:00', ['approval' => null]);
        $draft = cpPost($owner, $ig, '2026-12-07 03:00:00', ['status' => 'draft', 'approval' => null]);
        $published = cpPost($owner, $ig, '2026-12-08 03:00:00', ['status' => 'published', 'approval' => 'approved']);
        cpPost($owner, $ig, '2026-12-09 03:00:00', ['status' => 'cancelled', 'approval' => null]);
        cpPost($owner, $ig, '2026-12-10 03:00:00', ['status' => 'failed', 'approval' => null]);

        $plan = cpPlan($this, $owner);
        $token = cpToken($plan);

        $states = collect($this->getJson("/api/v1/a/{$token}")->json('data.posts'))->pluck('state', 'id');
        expect($states->all())->toBe([
            $waiting->ulid => 'waiting',
            $held->ulid => 'waiting',
            $changes->ulid => 'changes_requested',
            $approved->ulid => 'approved',
            $scheduled->ulid => 'scheduled',
            $published->ulid => 'published',
        ]);
        // What went out is shown, never decidable.
        $out = collect($this->getJson("/api/v1/a/{$token}")->json('data.posts'))->firstWhere('id', $published->ulid);
        expect($out['decidable'])->toBeFalse()->and($out['decidable_from'])->toBeNull();
        asApprover($this, 'rani@client.test')->postJson("/api/v1/a/{$token}/posts/{$published->ulid}/decision", ['decision' => 'revise', 'reason' => 'Too late'])
            ->assertUnprocessable();

        // The owner marks the draft for review: it shows (view only).
        $this->actingAs($owner)->postJson("/api/v1/social-posts/{$draft->ulid}/review", ['for_review' => true])
            ->assertOk()->assertJsonPath('data.for_review', true);
        $post = collect($this->getJson("/api/v1/a/{$token}")->json('data.posts'))->firstWhere('id', $draft->ulid);
        expect($post['state'])->toBe('draft')->and($post['decidable'])->toBeFalse();

        $this->actingAs($owner)->postJson("/api/v1/social-posts/{$draft->ulid}/review", ['for_review' => false])->assertOk();
        expect(cpIds($this, $token))->not->toContain($draft->ulid);

        // Only drafts are marked.
        $this->actingAs($owner)->postJson("/api/v1/social-posts/{$waiting->ulid}/review", ['for_review' => true])->assertUnprocessable();

        // Counts per state on the owner's list.
        $listed = collect($this->actingAs($owner)->getJson('/api/v1/social-approval-links')->json('data'))->firstWhere('id', $plan['id']);
        expect($listed['by_state'])->toMatchArray(['total' => 6, 'waiting' => 2, 'changes_requested' => 1, 'approved' => 1, 'scheduled' => 1, 'draft' => 0, 'published' => 1]);
    });

    it('shows a post published "now" (no slot) on the day it went out, in the owner timezone', function () {
        $owner = cpOwner();
        $ig = cpAccount($owner);
        // created_at is app-timezone (Asia/Jakarta) wall time: 1 Dec 00:30 WIB is inside; 30 Nov 23:30 WIB is not.
        $inside = cpPost($owner, $ig, '2026-12-01 00:00:00', ['status' => 'published', 'approval' => null]);
        $outside = cpPost($owner, $ig, '2026-12-01 00:00:00', ['status' => 'published', 'approval' => null]);
        SocialPost::query()->whereKey($inside->id)->update(['scheduled_at' => null, 'created_at' => '2026-12-01 00:30:00']);
        SocialPost::query()->whereKey($outside->id)->update(['scheduled_at' => null, 'created_at' => '2026-11-30 23:30:00']);
        // A draft without a slot never shows, even marked (it has no day).
        $draft = cpPost($owner, $ig, '2026-12-02 00:00:00', ['status' => 'draft', 'approval' => null]);
        SocialPost::query()->whereKey($draft->id)->update(['scheduled_at' => null, 'review_requested_at' => now(), 'created_at' => '2026-12-02 10:00:00']);

        $data = $this->getJson('/api/v1/a/'.cpToken(cpPlan($this, $owner)))->json('data');
        expect(collect($data['posts'])->pluck('id')->all())->toBe([$inside->ulid])
            ->and($data['posts'][0]['state'])->toBe('published')
            ->and($data['posts'][0]['local_date'])->toBe('2026-12-01')
            ->and($data['counts']['published'])->toBe(1);
    });

    it('includes posts added later, by anyone in the workspace — and nobody else\'s', function () {
        $owner = cpOwner();
        $ig = cpAccount($owner);
        $plan = cpPlan($this, $owner);
        $token = cpToken($plan);
        expect(cpIds($this, $token))->toBe([]);

        $later = cpPost($owner, $ig, '2026-12-12 03:00:00');
        $member = User::factory()->create();
        TeamMember::factory()->create(['owner_id' => $owner->id, 'member_id' => $member->id, 'status' => 'active']);
        $byMember = cpPost($member, $ig, '2026-12-13 03:00:00');
        $stranger = cpOwner();
        cpPost($stranger, cpAccount($stranger), '2026-12-14 03:00:00');

        expect(cpIds($this, $token))->toBe([$later->ulid, $byMember->ulid]);
    });

    it('returns a view-agnostic post payload: channels, media kind + aspect, state, decidability', function () {
        $owner = cpOwner();
        $ig = cpAccount($owner);
        $ulid = (string) str()->ulid();
        $file = File::factory()->for($owner)->create([
            'ulid' => $ulid, 'path' => "u/{$owner->ulid}/{$ulid}.jpg", 'width' => 1080, 'height' => 1350,
        ]);
        $post = cpPost($owner, $ig, '2026-12-02 03:00:00', ['media' => [
            ['url' => "https://bucket.r2.test/{$file->path}?X-Amz-Signature=x"],
            ['url' => 'https://cdn.test/clip.mp4', 'thumbnail_url' => 'https://cdn.test/cover.jpg'],
        ]]);

        $data = $this->getJson('/api/v1/a/'.cpToken(cpPlan($this, $owner)))->json('data');
        $p = $data['posts'][0];

        expect($data['kind'])->toBe('plan')
            ->and($data['counts'])->toMatchArray(['total' => 1, 'waiting' => 1])
            ->and($p['id'])->toBe($post->ulid)
            ->and($p['channels'])->toBe([['id' => $ig->ulid, 'platform' => 'instagram', 'handle' => 'instagram_brand']])
            ->and($p['media_items'][0])->toMatchArray(['type' => 'image', 'aspect' => 0.8, 'width' => 1080, 'height' => 1350])
            ->and($p['media_items'][1])->toMatchArray(['type' => 'video', 'aspect' => null, 'thumbnail_url' => 'https://cdn.test/cover.jpg'])
            ->and($p['state'])->toBe('waiting')
            ->and($p['decidable'])->toBeTrue()
            ->and($p['scheduled_at'])->not->toBeNull()
            ->and($p)->toHaveKeys(['caption', 'approval', 'comments', 'targets']);
    });
});

describe('deciding on a Content Plan', function () {
    it('keeps the #54 approver rules: 401 anonymous, 403 other email, 200 + audit for the invited one', function () {
        $owner = cpOwner();
        $post = cpPost($owner, cpAccount($owner), '2026-12-02 03:00:00');
        $token = cpToken(cpPlan($this, $owner));
        app('auth')->forgetGuards();

        $this->postJson("/api/v1/a/{$token}/posts/{$post->ulid}/decision", ['decision' => 'approve'])->assertStatus(401);
        asApprover($this, 'other@client.test')->postJson("/api/v1/a/{$token}/posts/{$post->ulid}/decision", ['decision' => 'approve'])
            ->assertStatus(403)->assertJsonPath('reason', 'approver_mismatch');
        asApprover($this, 'rani@client.test')->postJson("/api/v1/a/{$token}/posts/{$post->ulid}/decision", ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('data.approval', 'approved')->assertJsonPath('data.state', 'approved');

        expect(SocialApprovalDecision::query()->sole()->approver_email)->toBe('rani@client.test');
    });

    it('lets the client decide a post the editor held (awaiting) and approve all in scope', function () {
        $owner = cpOwner();
        $ig = cpAccount($owner);
        $held = cpPost($owner, $ig, '2026-12-02 03:00:00', ['approval' => 'awaiting']);
        $client = cpPost($owner, $ig, '2026-12-03 03:00:00', ['approval' => 'client']);
        $token = cpToken(cpPlan($this, $owner));

        asApprover($this, 'rani@client.test')->postJson("/api/v1/a/{$token}/approve-all", ['post_ids' => [$held->ulid, $client->ulid]])
            ->assertOk()
            ->assertJsonPath('data.results.0.approved', true)
            ->assertJsonPath('data.results.1.approved', true);
        expect(SocialApprovalDecision::query()->count())->toBe(2);
    });

    it('404s a post outside the scope', function () {
        $owner = cpOwner();
        $outside = cpPost($owner, cpAccount($owner), '2027-02-02 03:00:00');
        $token = cpToken(cpPlan($this, $owner));

        asApprover($this, 'rani@client.test')->postJson("/api/v1/a/{$token}/posts/{$outside->ulid}/decision", ['decision' => 'approve'])
            ->assertNotFound();
    });
});

describe('Free on a Content Plan', function () {
    it('shows the whole month but lets the client decide only the next 7 days', function () {
        $owner = cpOwner(paid: false);
        $ig = cpAccount($owner);
        // now = 20 Nov 03:00 UTC; the window ends 27 Nov 03:00 UTC.
        $inside = cpPost($owner, $ig, '2026-11-26 03:00:00');
        $outside = cpPost($owner, $ig, '2026-11-29 03:00:00');
        $plan = cpPlan($this, $owner, ['date_from' => '2026-11-20', 'date_to' => '2026-11-30']);
        $token = cpToken($plan);

        $posts = collect($this->getJson("/api/v1/a/{$token}")->json('data.posts'))->keyBy('id');
        expect($posts)->toHaveCount(2)
            ->and($posts[$inside->ulid]['decidable'])->toBeTrue()
            ->and($posts[$outside->ulid]['decidable'])->toBeFalse()
            ->and($posts[$outside->ulid]['decidable_from'])->toBe('2026-11-22T03:00:00Z');

        asApprover($this, 'rani@client.test')->postJson("/api/v1/a/{$token}/posts/{$outside->ulid}/decision", ['decision' => 'approve'])
            ->assertUnprocessable()
            ->assertJson(['reason' => 'outside_window', 'decidable_from' => '2026-11-22T03:00:00Z']);
        asApprover($this, 'rani@client.test')->postJson("/api/v1/a/{$token}/posts/{$inside->ulid}/decision", ['decision' => 'revise', 'reason' => 'Brighter'])
            ->assertOk();

        // Approve-all approves what is inside the window, nothing past it.
        $this->postJson("/api/v1/a/{$token}/approve-all", ['post_ids' => [$outside->ulid]])->assertUnprocessable();
        expect($outside->fresh()->approval->value)->toBe('client');

        // Two days later it is inside.
        $this->travel(3)->days();
        asApprover($this, 'rani@client.test')->postJson("/api/v1/a/{$token}/posts/{$outside->ulid}/decision", ['decision' => 'approve'])->assertOk();
    });

    it('applies the one-client rule to Content Plans (by approver email)', function () {
        $owner = cpOwner(paid: false);
        cpPlan($this, $owner);
        cpPlan($this, $owner, ['date_from' => '2027-01-01', 'date_to' => '2027-01-31']); // same client: fine

        $this->actingAs($owner)->postJson('/api/v1/social-approval-links', [
            'date_from' => '2027-01-01', 'date_to' => '2027-01-31', 'approver_email' => 'second@client.test',
        ])->assertStatus(402)->assertJsonPath('feature', 'approval_clients');
    });
});

describe('fixed-list links are unchanged', function () {
    it('still shows only the attached posts, as kind "list" with no scope', function () {
        $owner = cpOwner();
        $ig = cpAccount($owner);
        $on = cpPost($owner, $ig, '2026-12-02 03:00:00', ['status' => 'draft', 'approval' => null]);
        cpPost($owner, $ig, '2026-12-03 03:00:00'); // same dates, not on the list

        $link = $this->actingAs($owner)->postJson('/api/v1/social-approval-links', [
            'post_ids' => [$on->ulid], 'approver_email' => 'rani@client.test',
        ])->assertCreated()->json('data');

        expect($link['kind'])->toBe('list')->and($link['scope'])->toBeNull()->and($link['posts'])->toBe(1);
        $data = $this->getJson('/api/v1/a/'.basename($link['url']))->json('data');
        expect($data['kind'])->toBe('list')
            ->and($data['scope'])->toBeNull()
            ->and(collect($data['posts'])->pluck('id')->all())->toBe([$on->ulid]);

        // A fixed list takes no scope on update, and a plan takes no post list.
        $this->actingAs($owner)->patchJson("/api/v1/social-approval-links/{$link['id']}", ['date_from' => '2026-12-01'])->assertUnprocessable();
    });

    it('keeps links made before Content Plans working', function () {
        $owner = cpOwner();
        $post = cpPost($owner, cpAccount($owner), '2026-12-02 03:00:00');
        $old = SocialApprovalLink::create(['user_id' => $owner->id, 'approver_email' => 'rani@client.test']);
        $old->posts()->attach($post->id);

        expect(cpIds($this, $old->token))->toBe([$post->ulid]);
    });
});

describe('owner API', function () {
    it('validates the scope', function (array $body, string $field) {
        $owner = cpOwner();
        $foreign = cpAccount(cpOwner());
        $body = array_map(fn ($v) => $v === 'FOREIGN' ? [$foreign->ulid] : $v, $body);

        $this->actingAs($owner)->postJson('/api/v1/social-approval-links', $body + ['approver_email' => 'rani@client.test'])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    })->with([
        'neither list nor dates' => [[], 'post_ids'],
        'both list and dates' => [['post_ids' => ['01JXXXXXXXXXXXXXXXXXXXXXXX'], 'date_from' => '2026-12-01', 'date_to' => '2026-12-31'], 'post_ids'],
        'no end date' => [['date_from' => '2026-12-01'], 'date_to'],
        'ends before it starts' => [['date_from' => '2026-12-31', 'date_to' => '2026-12-01'], 'date_to'],
        'too long' => [['date_from' => '2026-01-01', 'date_to' => '2026-06-30'], 'date_to'],
        'not a date' => [['date_from' => '1 Dec', 'date_to' => '2026-12-31'], 'date_from'],
        'bad timezone' => [['date_from' => '2026-12-01', 'date_to' => '2026-12-31', 'timezone' => 'Mars/Base'], 'timezone'],
        'another workspace\'s channel' => [['date_from' => '2026-12-01', 'date_to' => '2026-12-31', 'channel_ids' => 'FOREIGN'], 'channel_ids'],
    ]);

    it('moves and narrows a plan on update, and refuses a post list on it', function () {
        $owner = cpOwner();
        $ig = cpAccount($owner);
        $tt = cpAccount($owner, 'tiktok');
        $dec = cpPost($owner, $ig, '2026-12-05 03:00:00');
        $jan = cpPost($owner, $tt, '2027-01-05 03:00:00');
        $plan = cpPlan($this, $owner);

        $updated = $this->actingAs($owner)->patchJson("/api/v1/social-approval-links/{$plan['id']}", [
            'date_from' => '2027-01-01', 'date_to' => '2027-01-31', 'channel_ids' => [$tt->ulid],
        ])->assertOk()->json('data.link');

        expect($updated['scope'])->toMatchArray(['date_from' => '2027-01-01', 'date_to' => '2027-01-31', 'channel_ids' => [$tt->ulid], 'timezone' => 'Asia/Jakarta'])
            ->and(cpIds($this, cpToken($plan)))->toBe([$jan->ulid])
            ->and($dec->ulid)->not->toBeIn(cpIds($this, cpToken($plan)));

        $this->actingAs($owner)->patchJson("/api/v1/social-approval-links/{$plan['id']}", ['add_post_ids' => [$dec->ulid]])
            ->assertUnprocessable();
    });

    it('creates a plan through an API key (MCP create_approval_link) and still requires approver_email', function () {
        ensurePassportKeys();
        $owner = cpOwner();
        $ig = cpAccount($owner);
        cpPost($owner, $ig, '2026-12-05 03:00:00');
        $key = $this->actingAs($owner, 'web')->postJson('/api/v1/me/api-keys', ['name' => 'k', 'scopes' => ['read', 'approve']])->json('data.key');
        app('auth')->forgetGuards();

        bearer($this, $key)->postJson('/api/v1/social-approval-links', ['date_from' => '2026-12-01', 'date_to' => '2026-12-31'])
            ->assertUnprocessable()->assertJsonValidationErrors('approver_email');

        bearer($this, $key)->postJson('/api/v1/social-approval-links', [
            'date_from' => '2026-12-01', 'date_to' => '2026-12-31', 'timezone' => 'Asia/Jakarta',
            'channel_ids' => [$ig->ulid], 'approver_email' => 'rani@client.test',
        ])->assertCreated()
            ->assertJsonPath('data.kind', 'plan')
            ->assertJsonPath('data.posts', 1)
            ->assertJsonPath('data.scope.channel_ids', [$ig->ulid]);
    });
});

describe('inboxes', function () {
    it('lists a Content Plan in "Waiting your approval" while it has posts waiting', function () {
        $owner = cpOwner();
        $ig = cpAccount($owner);
        $plan = cpPlan($this, $owner);
        app('auth')->forgetGuards();

        asApprover($this, 'rani@client.test')->getJson('/api/v1/me/approvals/waiting')->assertOk()->assertJsonCount(0, 'data');

        $post = cpPost($owner, $ig, '2026-12-05 03:00:00'); // added later
        cpPost($owner, $ig, '2026-12-06 03:00:00', ['approval' => 'approved']); // not waiting

        $this->getJson('/api/v1/me/approvals/waiting')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.link.kind', 'plan')
            ->assertJsonPath('data.0.link.url', $plan['url'])
            ->assertJsonPath('data.0.link.scope.date_from', '2026-12-01')
            ->assertJsonPath('data.0.waiting', 1)
            ->assertJsonPath('data.0.posts.0.id', $post->ulid)
            ->assertJsonPath('data.0.posts.0.decidable', true);
    });

    it('answers "Reports shared with you" with an empty list for now', function () {
        asApprover($this, 'rani@client.test')->getJson('/api/v1/me/reports/shared')->assertOk()->assertExactJson(['data' => []]);
        app('auth')->forgetGuards();
        $this->flushSession();
        $this->getJson('/api/v1/me/reports/shared')->assertUnauthorized();
    });
});
