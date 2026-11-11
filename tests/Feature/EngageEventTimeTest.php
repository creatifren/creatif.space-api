<?php

use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Models\User;
use App\Support\WebhookEventTime;
use App\Support\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * Engage P3: a comment filed from a replayed/reprocessed webhook keeps the
 * time it happened (from the provider payload, else when the webhook first
 * arrived), and webhook_events.created_at is stored in UTC from now on —
 * old WIB rows are read correctly, never rewritten.
 */

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
    Http::fake(['api.outstand.test/*' => Http::response(['success' => true, 'data' => [], 'count' => 0])]);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'UTC'));
});

function etAccount(): SocialAccount
{
    return SocialAccount::factory()->for(User::factory()->create())->create([
        'platform' => 'instagram', 'provider' => 'outstand', 'provider_account_id' => 'beUgT',
        'username' => 'creatif_space', 'status' => 'connected', 'inbox_synced_at' => now(),
    ]);
}

function etHook($test, array $data, array $envelope = [])
{
    $body = json_encode(['event' => 'comment.received', 'data' => $data] + $envelope, JSON_THROW_ON_ERROR);

    return $test->call('POST', '/api/v1/webhooks/outstand', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_OUTSTAND_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'whsec_out'),
    ], $body);
}

function etComment(string $account = 'beUgT', string $id = 'c-1', array $extra = []): array
{
    return ['network' => 'instagram', 'accountId' => $account, 'commentId' => $id, 'platformPostId' => '1805',
        'authorId' => '1784555', 'authorUsername' => 'rizal.dev', 'text' => 'Halo'] + $extra;
}

/** commented_at as a UTC string. */
function etAt(SocialComment $c): string
{
    return CarbonImmutable::instance($c->commented_at)->utc()->format('Y-m-d H:i:s');
}

describe('webhook_events in UTC', function () {
    it('stores new rows in UTC and says so', function () {
        etAccount();
        etHook($this, etComment())->assertOk();

        $row = DB::table('webhook_events')->sole();
        // 10:00 UTC — not 17:00 WIB as before.
        expect((string) $row->created_at)->toStartWith('2026-11-20 10:00:00')
            ->and((bool) $row->created_at_utc)->toBeTrue()
            ->and(WebhookEventTime::receivedAt($row)?->toIso8601ZuluString())->toBe('2026-11-20T10:00:00Z');
    });

    it('reads an old WIB row as the same instant, without rewriting it', function () {
        DB::table('webhook_events')->insert([
            'source' => 'outstand', 'event_id' => 'old', 'type' => 'inbox.comment', 'payload' => '{}',
            'created_at' => '2026-11-20 17:00:00', 'updated_at' => '2026-11-20 17:00:00', // WIB wall time
        ]);

        $row = DB::table('webhook_events')->sole();
        expect((bool) $row->created_at_utc)->toBeFalse()
            ->and(WebhookEventTime::receivedAt($row)?->toIso8601ZuluString())->toBe('2026-11-20T10:00:00Z')
            ->and((string) $row->created_at)->toStartWith('2026-11-20 17:00:00');
    });

    it('stores Creem receipts in UTC too', function () {
        expect(WebhookEventTime::stamp())->toBe([
            'created_at' => '2026-11-20 10:00:00', 'updated_at' => '2026-11-20 10:00:00', 'created_at_utc' => true,
        ]);
    });
});

describe('comment time', function () {
    it('takes the comment\'s own time when the payload has it', function () {
        etAccount();
        etHook($this, etComment(extra: ['createdAt' => '2026-11-19T08:15:00Z']))->assertOk();

        expect(etAt(SocialComment::sole()))->toBe('2026-11-19 08:15:00');
    });

    it('takes the event time from the provider envelope when the comment has none, and stores it for replays', function () {
        etAccount();
        etHook($this, etComment(), ['timestamp' => '2026-11-20T09:30:00Z'])->assertOk();

        expect(etAt(SocialComment::sole()))->toBe('2026-11-20 09:30:00')
            ->and(json_decode((string) DB::table('webhook_events')->value('payload'), true)['event_at'])->toBe('2026-11-20T09:30:00Z');
    });

    it('keeps the original event time when a dropped comment is reprocessed days later', function () {
        // Arrives for an account we do not know yet: stored, dropped.
        etHook($this, etComment('Pjnf0'), ['timestamp' => '2026-11-20T09:30:00Z'])->assertOk();
        expect(DB::table('webhook_events')->value('outcome'))->toBe('dropped:unknown_account');

        // Three days later the account is known and the event is replayed.
        $this->travel(3)->days();
        SocialAccount::factory()->for(User::factory()->create())->create([
            'platform' => 'instagram', 'provider' => 'outstand', 'provider_account_id' => 'Pjnf0',
            'username' => 'creatif_space', 'status' => 'connected',
        ]);
        $this->artisan('inbox:reprocess-webhooks', ['--force' => true, '--skip-refs' => true])->assertSuccessful();

        expect(etAt(SocialComment::sole()))->toBe('2026-11-20 09:30:00');
    });

    it('falls back to when the webhook arrived for rows stored before (WIB created_at, no time in the payload)', function () {
        DB::table('webhook_events')->insert([
            'source' => 'outstand', 'event_id' => 'evt_old', 'type' => 'inbox.comment', 'outcome' => 'dropped:unknown_account',
            'payload' => json_encode(['id' => 'c-old', 'account_id' => 'beUgT', 'post_id' => null, 'platform_post_id' => '1805',
                'author_id' => '1784555', 'author_username' => 'rizal.dev', 'text' => 'Halo', 'network' => 'instagram']),
            // Received 18 Nov 12:00 WIB = 05:00 UTC, as rows were written before this fix.
            'created_at' => '2026-11-18 12:00:00', 'updated_at' => '2026-11-18 12:00:00',
        ]);
        etAccount();

        $this->artisan('inbox:reprocess-webhooks', ['--force' => true, '--skip-refs' => true])->assertSuccessful();

        expect(etAt(SocialComment::sole()))->toBe('2026-11-18 05:00:00');
    });

    it('still uses now for a live comment with no time anywhere', function () {
        etAccount();
        etHook($this, etComment())->assertOk();

        expect(etAt(SocialComment::sole()))->toBe('2026-11-20 10:00:00');
    });
});
