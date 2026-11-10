<?php

use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Models\User;
use App\Notifications\InboxActivity;
use App\Support\Workspace;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * QA 11 Nov (after #45): the IG comment webhook named Outstand account
 * 'Pjnf0' — an older connect of @creatif_space (30 Sep, no tenant, purged
 * here) — while the live account is 'beUgT'. Both share Meta's webhook
 * reference id 17841439249433892.
 */

const REF = '17841439249433892';

beforeEach(function () {
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'social_posts_monthly' => 0, 'credits_monthly' => 0]]);
    }
    config([
        'services.social.provider' => 'outstand',
        'services.outstand.key' => 'sk_test',
        'services.outstand.base_url' => 'https://api.outstand.test/v1',
        'services.outstand.webhook_secret' => 'whsec_out',
        'services.inbox.enabled' => true,
    ]);
    Cache::flush();
    Workspace::forget();
});

function wmLive(array $attrs = []): SocialAccount
{
    return SocialAccount::factory()->for(User::factory()->create())->create($attrs + [
        'platform' => 'instagram', 'provider' => 'outstand', 'provider_account_id' => 'beUgT',
        'username' => 'creatif_space', 'network_unique_id' => '28161365190200706',
        'network_webhook_reference_id' => REF, 'status' => 'connected', 'inbox_synced_at' => now(),
    ]);
}

/** Outstand's GET /social-accounts?id= for the old account (and others). */
function wmFakeOutstand(array $byId, int $status = 200): void
{
    Http::fake(['api.outstand.test/v1/social-accounts*' => function (HttpRequest $r) use ($byId, $status) {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
        $row = $byId[$q['id'] ?? ''] ?? null;

        return Http::response($status === 200
            ? ['success' => true, 'data' => $row === null ? [] : [$row], 'count' => $row === null ? 0 : 1]
            : ['error' => 'down'], $status);
    }]);
}

function wmOld(string $network = 'instagram', ?string $ref = REF): array
{
    return ['id' => 'Pjnf0', 'network' => $network, 'username' => 'creatif_space', 'tenant_id' => null,
        'network_unique_id' => '28161365190200704', 'network_webhook_reference_id' => $ref, 'isActive' => 1];
}

function wmHook($test, array $data)
{
    $body = json_encode(['event' => 'comment.received', 'data' => $data], JSON_THROW_ON_ERROR);

    return $test->call('POST', '/api/v1/webhooks/outstand', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_OUTSTAND_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'whsec_out'),
    ], $body);
}

function wmComment(string $id = '17990000000000009'): array
{
    return ['network' => 'instagram', 'accountId' => 'Pjnf0', 'postId' => null, 'platformPostId' => '18050000000000000',
        'commentId' => $id, 'authorId' => '1784555', 'authorUsername' => 'rizal.dev', 'text' => 'Halo'];
}

function wmOutcome(): ?string
{
    return DB::table('webhook_events')->where('type', 'inbox.comment')->latest('id')->value('outcome');
}

describe('an unknown Outstand account in a webhook', function () {
    it('is filed on the one connected row with the same webhook reference id', function () {
        $live = wmLive();
        wmFakeOutstand(['Pjnf0' => wmOld()]);

        wmHook($this, wmComment())->assertOk();

        expect(wmOutcome())->toBe('filed:matched_by_webhook_ref')
            ->and(SocialComment::sole())->social_account_id->toBe($live->id)->user_id->toBe($live->user_id);
        expect($live->user->notifications()->where('type', InboxActivity::class)->count())->toBe(1);

        $this->actingAs($live->user)->getJson('/api/v1/inbox/comments')->assertJsonCount(1, 'data');
    });

    it('asks Outstand about an unknown account once, then remembers it', function () {
        wmLive();
        wmFakeOutstand(['Pjnf0' => wmOld()]);

        wmHook($this, wmComment('c1'))->assertOk();
        wmHook($this, wmComment('c2'))->assertOk();

        Http::assertSentCount(1);
        expect(SocialComment::count())->toBe(2);
    });

    it('never matches across platforms', function () {
        wmLive(['platform' => 'facebook']);
        wmFakeOutstand(['Pjnf0' => wmOld('instagram')]);

        wmHook($this, wmComment())->assertOk();

        expect(wmOutcome())->toBe('dropped:unknown_account')->and(SocialComment::count())->toBe(0);
    });

    it('drops it when the reference id matches no connected row, or several', function (string $case) {
        if ($case === 'none') {
            wmLive(['status' => 'disconnected']);
        } else {
            wmLive();
            wmLive(['provider_account_id' => 'other1']);
        }
        wmFakeOutstand(['Pjnf0' => wmOld()]);

        wmHook($this, wmComment())->assertOk();

        expect(wmOutcome())->toBe('dropped:unknown_account')->and(SocialComment::count())->toBe(0);
    })->with(['none', 'several']);

    it('drops it when Outstand reports no reference id', function () {
        wmLive();
        wmFakeOutstand(['Pjnf0' => wmOld(ref: null)]);

        wmHook($this, wmComment())->assertOk();

        expect(wmOutcome())->toBe('dropped:unknown_account')->and(SocialComment::count())->toBe(0);
    });

    it('does not remember a failed lookup', function () {
        wmLive();
        wmFakeOutstand([], 500);

        wmHook($this, wmComment('c2'))->assertOk();
        wmHook($this, wmComment('c3'))->assertOk();

        expect(wmOutcome())->toBe('dropped:unknown_account');
        Http::assertSentCount(2);
    });

    it('still files a known account directly, without asking Outstand', function () {
        $live = wmLive();
        Http::fake();

        wmHook($this, ['accountId' => 'beUgT'] + wmComment())->assertOk();

        expect(wmOutcome())->toBe('filed')->and(SocialComment::sole()->social_account_id)->toBe($live->id);
        Http::assertNothingSent();
    });
});

it('stores the webhook reference id when accounts sync', function () {
    $user = User::factory()->create();
    Http::fake(['api.outstand.test/*' => Http::response(['success' => true, 'data' => [[
        'id' => 'beUgT', 'network' => 'instagram', 'username' => 'creatif_space', 'tenant_id' => $user->ulid, 'isActive' => 1,
        'network_unique_id' => '28161365190200706', 'network_webhook_reference_id' => REF,
    ]], 'count' => 1, 'total' => 1])]);

    $this->actingAs($user)->postJson('/api/v1/social-accounts/sync')->assertOk();

    expect(SocialAccount::sole()->network_webhook_reference_id)->toBe(REF);
});

describe('inbox:reprocess-webhooks', function () {
    beforeEach(function () {
        // The stored event as #45 left it: dropped, in our normalised shape.
        DB::table('webhook_events')->insert([
            'source' => 'outstand', 'event_id' => 'evt_1', 'type' => 'inbox.comment', 'outcome' => 'dropped:unknown_account',
            'payload' => json_encode(['id' => '17990000000000009', 'account_id' => 'Pjnf0', 'post_id' => null,
                'platform_post_id' => '18050000000000000', 'author_id' => '1784555', 'author_username' => 'rizal.dev',
                'text' => 'Halo', 'network' => 'instagram']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    it('is a dry run without --force', function () {
        wmLive(['network_webhook_reference_id' => null]);
        wmFakeOutstand(['Pjnf0' => wmOld(), 'beUgT' => wmOld() + ['id' => 'beUgT']]);

        $this->artisan('inbox:reprocess-webhooks')->assertSuccessful();

        expect(SocialComment::count())->toBe(0)
            ->and(SocialAccount::sole()->network_webhook_reference_id)->toBeNull()
            ->and(wmOutcome())->toBe('dropped:unknown_account');
    });

    it('backfills the live row\'s reference id, files the comment quietly, and is idempotent', function () {
        $live = wmLive(['network_webhook_reference_id' => null]);
        wmFakeOutstand(['Pjnf0' => wmOld(), 'beUgT' => ['id' => 'beUgT', 'network' => 'instagram', 'network_webhook_reference_id' => REF]]);

        $this->artisan('inbox:reprocess-webhooks', ['--force' => true])
            ->expectsOutputToContain('filed:matched_by_webhook_ref: 1')
            ->assertSuccessful();

        expect($live->fresh()->network_webhook_reference_id)->toBe(REF)
            ->and(SocialComment::sole())->social_account_id->toBe($live->id)
            ->and(wmOutcome())->toBe('filed:matched_by_webhook_ref')
            ->and($live->user->notifications()->count())->toBe(0);

        $this->artisan('inbox:reprocess-webhooks', ['--force' => true])
            ->expectsOutputToContain('0 stored event(s) to replay.')
            ->assertSuccessful();
        expect(SocialComment::count())->toBe(1);
    });
});
