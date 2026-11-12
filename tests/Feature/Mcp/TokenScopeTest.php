<?php

use App\Models\File;
use App\Models\McpAuditLog;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

beforeEach(function () {
    ensurePassportKeys();
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
    ]);
    Http::fake();
});

function apiKeyFor(TestCase $test, User $user, array $scopes): string
{
    $key = $test->actingAs($user, 'web')
        ->postJson('/api/v1/me/api-keys', ['name' => 'Test key', 'scopes' => $scopes])
        ->json('data.key');
    app('auth')->forgetGuards();

    return $key;
}

describe('the scope fence', function () {
    it('lets read reach the read routes', function (string $uri) {
        $user = User::factory()->create();
        $key = apiKeyFor($this, $user, ['read']);

        bearer($this, $key)->getJson($uri)->assertOk();
    })->with([
        '/api/v1/me',
        '/api/v1/social-accounts',
        '/api/v1/social-posts',
        '/api/v1/files',
        '/api/v1/credits',
        '/api/v1/me/subscription',
    ]);

    it('refuses routes outside the MCP allowlist, whatever the scope', function (string $method, string $uri) {
        $user = User::factory()->create();
        $account = SocialAccount::factory()->for($user)->create();
        $post = SocialPost::factory()->for($user)->create(['status' => 'draft']);
        $key = apiKeyFor($this, $user, ['read', 'draft']);

        bearer($this, $key)->json($method, str_replace(['{account}', '{post}'], [$account->ulid, $post->ulid], $uri))
            ->assertForbidden()
            ->assertJsonPath('error', 'not_available');
    })->with([
        ['GET', '/api/v1/me/storage'],
        ['POST', '/api/v1/credits/top-up'],
        ['DELETE', '/api/v1/social-accounts/{account}'],
        ['POST', '/api/v1/social-posts/{post}/approve'],
        // Cancelling a scheduled post stays app-only (discard_draft is drafts only).
        ['DELETE', '/api/v1/social-posts/{post}'],
        ['GET', '/api/v1/team/members'],
    ]);

    it('needs draft to save a draft', function () {
        $user = User::factory()->create();
        $ig = SocialAccount::factory()->for($user)->create();
        $key = apiKeyFor($this, $user, ['read']);

        bearer($this, $key)
            ->postJson('/api/v1/social-posts', ['caption' => 'Hi', 'account_ids' => [$ig->ulid], 'draft' => true])
            ->assertForbidden()
            ->assertJsonPath('error', 'insufficient_scope')
            ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="draft"');

        expect(SocialPost::query()->count())->toBe(0);
    });

    it('saves a draft with draft scope and never reaches the provider', function () {
        $user = User::factory()->create();
        $ig = SocialAccount::factory()->for($user)->create();
        $key = apiKeyFor($this, $user, ['draft']);

        bearer($this, $key)
            ->postJson('/api/v1/social-posts', [
                'caption' => 'Launch day',
                'account_ids' => [$ig->ulid],
                'draft' => true,
                'scheduled_at' => now()->addDays(2)->toIso8601String(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        Http::assertNothingSent();
        expect(SocialPost::query()->sole()->scheduled_at)->not->toBeNull();
    });

    it('refuses a send, a schedule or an approval request in draft scope', function (array $extra) {
        $user = User::factory()->create();
        $ig = SocialAccount::factory()->for($user)->create();
        $key = apiKeyFor($this, $user, ['read', 'draft']);

        bearer($this, $key)
            ->postJson('/api/v1/social-posts', ['caption' => 'Hi', 'account_ids' => [$ig->ulid]] + $extra)
            ->assertForbidden()
            ->assertJsonPath('error', 'draft_only');

        Http::assertNothingSent();
        expect(SocialPost::query()->count())->toBe(0);
    })->with([
        'send now' => [[]],
        'draft false' => [['draft' => false]],
        'schedule' => [['scheduled_at' => now()->addDay()->toIso8601String()]],
        'approval' => [['draft' => true, 'approval' => 'client']],
    ]);

    it('applies to OAuth tokens the same way', function () {
        $user = User::factory()->create();
        $tokens = oauthTokens($this, $user, mcpClient(), 'read');

        bearer($this, $tokens['access_token'])->getJson('/api/v1/credits')->assertOk();
        bearer($this, $tokens['access_token'])->getJson('/api/v1/me/storage')->assertForbidden();
        bearer($this, $tokens['access_token'])
            ->postJson('/api/v1/social-posts', ['caption' => 'x', 'account_ids' => ['a'], 'draft' => true])
            ->assertForbidden()
            ->assertJsonPath('error', 'insufficient_scope');
    });

    it('leaves the SPA cookie unfenced', function () {
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/v1/spaces')->assertOk();
    });

    it('never needs Passport\'s key for guests, cookies or API keys', function () {
        config(['passport.public_key' => 'not-a-key', 'passport.private_key' => 'not-a-key']);
        $user = User::factory()->create();
        $key = apiKeyFor($this, $user, ['read']);

        $this->getJson('/api/v1/me')->assertUnauthorized();
        bearer($this, $key)->getJson('/api/v1/me')->assertOk();
        // A JWT cannot be checked without the key: refused, not a 500.
        bearer($this, 'eyJ0eXAiOiJKV1QifQ.eyJqdGkiOiJ4In0.sig')->getJson('/api/v1/me')->assertUnauthorized();
    });

    it('answers 401 for an unknown bearer', function () {
        bearer($this, 'cs_live_'.str_repeat('a', 48))->getJson('/api/v1/me')->assertUnauthorized();
        bearer($this, 'not-a-token')->getJson('/api/v1/me')->assertUnauthorized();
    });
});

describe('rate limit and audit', function () {
    it('limits each token per minute', function () {
        config(['mcp.rate_limit_per_minute' => 2]);
        $user = User::factory()->create();
        $key = apiKeyFor($this, $user, ['read']);
        $other = apiKeyFor($this, $user, ['read']);

        bearer($this, $key)->getJson('/api/v1/me')->assertOk()->assertHeader('X-RateLimit-Limit', '2');
        bearer($this, $key)->getJson('/api/v1/me')->assertOk();
        bearer($this, $key)->getJson('/api/v1/me')
            ->assertStatus(429)
            ->assertJsonPath('error', 'rate_limited')
            ->assertHeader('Retry-After');

        // Another token has its own bucket.
        bearer($this, $other)->getJson('/api/v1/me')->assertOk();
    });

    it('writes who, token, client, tool, route and status — never content', function () {
        $user = User::factory()->create();
        $ig = SocialAccount::factory()->for($user)->create();
        $key = apiKeyFor($this, $user, ['read', 'draft']);

        bearer($this, $key)
            ->withHeaders(['X-MCP-Tool' => 'draft_post', 'X-MCP-Client' => 'Claude Desktop'])
            ->postJson('/api/v1/social-posts', ['caption' => 'Secret launch caption', 'account_ids' => [$ig->ulid], 'draft' => true])
            ->assertCreated();
        bearer($this, $key)->getJson('/api/v1/me/storage')->assertForbidden();

        $rows = McpAuditLog::query()->orderBy('id')->get();
        expect($rows)->toHaveCount(2)
            ->and($rows[0]->only(['user_id', 'token_kind', 'tool', 'client_name', 'route', 'method', 'status']))->toBe([
                'user_id' => $user->id,
                'token_kind' => 'api_key',
                'tool' => 'draft_post',
                'client_name' => 'Claude Desktop',
                'route' => 'api.v1.social-posts.store',
                'method' => 'POST',
                'status' => 201,
            ])
            ->and($rows[1]->status)->toBe(403)
            ->and(json_encode($rows->toArray()))->not->toContain('Secret launch caption');

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/me/mcp-activity')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status', 403)
            ->assertJsonPath('data.1.tool', 'draft_post');
    });

    it('records the OAuth client by its registration', function () {
        $user = User::factory()->create();
        $client = mcpClient();
        $tokens = oauthTokens($this, $user, $client);

        bearer($this, $tokens['access_token'])->withHeaders(['X-MCP-Tool' => 'get_credits'])->getJson('/api/v1/credits');

        expect(McpAuditLog::query()->sole()->only(['token_kind', 'client_id', 'client_name', 'tool']))->toBe([
            'token_kind' => 'oauth',
            'client_id' => (string) $client->getKey(),
            'client_name' => 'Claude',
            'tool' => 'get_credits',
        ]);
    });
});

describe('V1 additions for MCP', function () {
    it('shows one post to its owner only', function () {
        $user = User::factory()->create();
        $post = SocialPost::factory()->for($user)->create(['caption' => 'Mine']);

        $this->actingAs($user, 'web')->getJson('/api/v1/social-posts/'.$post->ulid)
            ->assertOk()->assertJsonPath('data.caption', 'Mine')->assertJsonPath('data.id', $post->ulid);

        app('auth')->forgetGuards();
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/v1/social-posts/'.$post->ulid)->assertNotFound();
    });

    it('filters posts by channel, date window and limit', function () {
        $user = User::factory()->create();
        $ig = SocialAccount::factory()->for($user)->create();
        $fb = SocialAccount::factory()->for($user)->create(['platform' => 'facebook']);

        $a = SocialPost::factory()->for($user)->create(['scheduled_at' => now()->addDays(2)]);
        $a->targets()->create(['social_account_id' => $ig->id, 'status' => 'pending']);
        $b = SocialPost::factory()->for($user)->create(['scheduled_at' => now()->addDays(10)]);
        $b->targets()->create(['social_account_id' => $fb->id, 'status' => 'pending']);

        $this->actingAs($user, 'web');

        $this->getJson('/api/v1/social-posts?account_id='.$ig->ulid)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->ulid);

        $this->getJson('/api/v1/social-posts?'.http_build_query([
            'from' => now()->addDays(5)->toDateString(),
            'to' => now()->addDays(11)->toDateString(),
        ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $b->ulid);

        $this->getJson('/api/v1/social-posts?limit=1')->assertOk()->assertJsonCount(1, 'data');
    });

    it('builds draft media from library file ids', function () {
        $user = User::factory()->create();
        $ig = SocialAccount::factory()->for($user)->create();
        $file = File::factory()->for($user)->create();

        $this->actingAs($user, 'web')
            ->postJson('/api/v1/social-posts', [
                'caption' => 'With a photo',
                'account_ids' => [$ig->ulid],
                'file_ids' => [$file->ulid],
                'draft' => true,
            ])->assertCreated();

        $media = SocialPost::query()->sole()->media;
        expect($media)->toHaveCount(1)->and($media[0]['url'])->toStartWith('https://');
    });

    it('names the files it cannot use', function () {
        $user = User::factory()->create();
        $ig = SocialAccount::factory()->for($user)->create();
        $foreign = File::factory()->create();

        $this->actingAs($user, 'web')
            ->postJson('/api/v1/social-posts', [
                'caption' => 'x',
                'account_ids' => [$ig->ulid],
                'file_ids' => [$foreign->ulid],
                'draft' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file_ids']);
    });
});
