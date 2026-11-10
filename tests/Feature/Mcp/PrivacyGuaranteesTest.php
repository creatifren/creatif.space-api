<?php

use App\Jobs\PruneMcpAuditLogs;
use App\Models\McpAuditLog;
use App\Models\User;
use App\Notifications\WaitlistOnTheList;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * What the privacy page promises about MCP: audit logs kept 90 days, and
 * every key, token, client and audit row gone with the account.
 */

beforeEach(function () {
    ensurePassportKeys();
    Http::fake();
});

function privacyAudit(User $user, CarbonInterface|DateTimeInterface $at): McpAuditLog
{
    $row = McpAuditLog::query()->create([
        'user_id' => $user->id, 'token_kind' => 'api_key', 'token_id' => '1',
        'route' => 'api/v1/credits', 'method' => 'GET', 'status' => 200,
    ]);
    $row->forceFill(['created_at' => $at])->save();

    return $row;
}

function privacyApiKey(TestCase $test, User $user): string
{
    $key = $test->actingAs($user, 'web')
        ->postJson('/api/v1/me/api-keys', ['name' => 'Desktop', 'scopes' => ['read']])
        ->assertCreated()
        ->json('data.key');
    app('auth')->forgetGuards();

    return $key;
}

it('prunes MCP audit logs older than the retention window, daily', function () {
    $user = User::factory()->create();
    $old = privacyAudit($user, now()->subDays(91));
    $edge = privacyAudit($user, now()->subDays(89));

    (new PruneMcpAuditLogs)->handle();

    expect(McpAuditLog::query()->find($old->id))->toBeNull()
        ->and(McpAuditLog::query()->find($edge->id))->not->toBeNull()
        ->and(config('mcp.audit_retention_days'))->toBe(90);

    config(['mcp.audit_retention_days' => 30]);
    (new PruneMcpAuditLogs)->handle();
    expect(McpAuditLog::query()->find($edge->id))->toBeNull();

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->description, PruneMcpAuditLogs::class));
    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('50 2 * * *');
});

it('deletes API keys, OAuth tokens and clients, and audit logs with the account', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $key = privacyApiKey($this, $user);
    $otherKey = privacyApiKey($this, $other);
    $tokens = oauthTokens($this, $user, mcpClient(), 'read');
    oauthTokens($this, $other, mcpClient(), 'read');
    $ownedClient = (string) Str::uuid();
    DB::table('oauth_clients')->insert([
        'id' => $ownedClient, 'owner_type' => $user->getMorphClass(), 'owner_id' => $user->id, 'name' => 'Mine',
        'redirect_uris' => '[]', 'grant_types' => '[]', 'revoked' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    privacyAudit($user, now());
    privacyAudit($other, now());

    bearer($this, $key)->getJson('/api/v1/credits')->assertOk();
    expect(DB::table('oauth_access_tokens')->where('user_id', $user->id)->count())->toBe(1)
        ->and(DB::table('oauth_refresh_tokens')->count())->toBe(2);

    $user->delete(); // the account-deletion path: a soft delete

    expect($user->tokens()->count())->toBe(0)
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(0)
        ->and(DB::table('oauth_access_tokens')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('oauth_refresh_tokens')->count())->toBe(1)
        ->and(DB::table('oauth_auth_codes')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('oauth_clients')->where('id', $ownedClient)->exists())->toBeFalse()
        ->and(McpAuditLog::query()->where('user_id', $user->id)->count())->toBe(0);

    bearer($this, $key)->getJson('/api/v1/credits')->assertUnauthorized();
    bearer($this, $tokens['access_token'])->getJson('/api/v1/credits')->assertUnauthorized();

    // The other account keeps everything.
    bearer($this, $otherKey)->getJson('/api/v1/credits')->assertOk();
    expect(DB::table('oauth_access_tokens')->where('user_id', $other->id)->count())->toBe(1)
        ->and(McpAuditLog::query()->where('user_id', $other->id)->count())->toBeGreaterThanOrEqual(1);
});

it('links the transactional mail header to the marketing site, not the API', function () {
    config(['app.url' => 'https://api.creatif.space', 'app.marketing_url' => 'https://creatif.space']);

    $html = (string) (new WaitlistOnTheList('https://creatif.space/mcp?ref=x'))->toMail(new AnonymousNotifiable)->render();

    expect($html)->toContain('href="https://creatif.space"')
        ->and($html)->not->toContain('href="https://api.creatif.space"');
});
