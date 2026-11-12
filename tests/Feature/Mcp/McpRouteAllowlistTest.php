<?php

use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\ApprovalReminder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/*
 * The token fence (config/mcp.php routes) against what the MCP server
 * really calls. QA P2: send_approval_reminder answered 403 "not available
 * to connected apps or API keys yet" because POST
 * /social-approval-links/{id}/remind was not allowlisted.
 */

beforeEach(fn () => ensurePassportKeys());

/** A personal API key with these scopes (made through the session, as the app does). */
function alKey(TestCase $test, User $user, array $scopes): string
{
    $key = $test->actingAs($user, 'web')
        ->postJson('/api/v1/me/api-keys', ['name' => 'Allowlist key', 'scopes' => $scopes])
        ->assertCreated()
        ->json('data.key');
    app('auth')->forgetGuards();

    return $key;
}

/** A paid owner with one post waiting on a link addressed to rani@client.test. */
function alLink(): array
{
    $owner = User::factory()->create(['name' => 'Rizal']);
    Subscription::factory()->for($owner)->create();
    $owner = User::query()->findOrFail($owner->id);
    $post = SocialPost::factory()->for($owner)->create([
        'provider_post_id' => null, 'status' => 'scheduled', 'approval' => 'client', 'scheduled_at' => now()->addDays(2),
    ]);
    $post->targets()->create(['social_account_id' => SocialAccount::factory()->for($owner)->create()->id, 'status' => 'pending']);
    $link = SocialApprovalLink::create(['user_id' => $owner->id, 'title' => 'November', 'approver_email' => 'rani@client.test']);
    $link->posts()->attach($post->id);

    return [$owner, $link];
}

it('lets a token with approve send an approval reminder (send_approval_reminder)', function () {
    Notification::fake();
    [$owner, $link] = alLink();
    $key = alKey($this, $owner, ['read', 'approve']);

    bearer($this, $key)->postJson("/api/v1/social-approval-links/{$link->ulid}/remind")
        ->assertOk()
        ->assertJsonPath('data.link_id', $link->ulid);
    Notification::assertSentOnDemandTimes(ApprovalReminder::class, 1);
});

it('answers a token without approve with the standard insufficient_scope 403, and sends nothing', function () {
    Notification::fake();
    [$owner, $link] = alLink();
    $key = alKey($this, $owner, ['read', 'draft']);

    bearer($this, $key)->postJson("/api/v1/social-approval-links/{$link->ulid}/remind")
        ->assertForbidden()
        ->assertJsonPath('error', 'insufficient_scope')
        ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="approve"');
    Notification::assertNothingSent();
});

/**
 * Every V1 call in mcp/src: method + path, `${…}` as a placeholder id, a
 * path held in a `const` resolved from the same file.
 *
 * @return list<array{0: string, 1: string, 2: string}> [METHOD, path, file]
 */
function mcpCalls(): array
{
    $calls = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('mcp/src')));
    foreach ($files as $file) {
        if (! str_ends_with((string) $file, '.ts')) {
            continue;
        }
        $src = (string) file_get_contents((string) $file);
        preg_match_all('/const (\w+) = `([^`]+)`;/', $src, $c);
        $consts = array_combine($c[1], $c[2]);
        preg_match_all('/v1\.(get|post|patch|put|delete)\s*(?:<(?:[^<>]|<[^<>]*>)*>)?\(\s*(?:([`\'"])(.+?)\2|(\w+)\s*[,)])/s', $src, $m, PREG_SET_ORDER);
        foreach ($m as $hit) {
            $path = ($hit[3] ?? '') !== '' ? $hit[3] : ($consts[$hit[4] ?? ''] ?? null);
            expect($path, "unresolved V1 path in {$file}: {$hit[0]}")->not->toBeNull();
            $path = preg_replace_callback('/\$\{(\w+)\}/', fn ($v) => $consts[$v[1]] ?? $v[0], (string) $path);
            $path = (string) preg_replace('/\$\{[^}]+\}/', '01JZZZZZZZZZZZZZZZZZZZZZZZ', (string) $path);
            $calls[] = [strtoupper($hit[1]), $path, str_replace(base_path().'/', '', (string) $file)];
        }
    }

    return $calls;
}

it('allowlists every route the MCP server calls, so no tool answers "not available to connected apps"', function () {
    $calls = mcpCalls();
    expect(count($calls))->toBeGreaterThan(40);

    $routes = Route::getRoutes();
    $allowed = config('mcp.routes');
    $missing = [];
    foreach ($calls as [$method, $path, $file]) {
        $route = $routes->match(Request::create('/api/v1'.$path, $method));
        $name = $route->getName();
        if (! is_string($name) || ! array_key_exists($name, $allowed)) {
            $missing[] = "{$method} {$path} ({$name}) in {$file}";
        }
    }

    expect($missing)->toBe([]);
});

it('knows the reminder call it is guarding', function () {
    expect(collect(mcpCalls())->contains(fn ($c) => $c[0] === 'POST' && str_ends_with($c[1], '/remind') && $c[2] === 'mcp/src/tools/m2.ts'))->toBeTrue()
        ->and(config('mcp.routes')['api.v1.social-approval-links.remind'])->toBe('approve');
});
