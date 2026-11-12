<?php

use App\Models\McpAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

/*
 * mcp:usage — the daily launch check: API calls per tool, error rate and
 * top error codes from mcp_audit_logs. Aggregates only: no user, token, IP
 * or client ever appears in the output.
 */

function usageRow(User $user, ?string $tool, int $status, array $extra = []): void
{
    McpAuditLog::query()->create($extra + [
        'user_id' => $user->id,
        'token_kind' => 'api_key',
        'token_id' => '42',
        'client_name' => 'Secret Client Name',
        'tool' => $tool,
        'route' => 'api.v1.social-posts.index',
        'method' => 'GET',
        'status' => $status,
        'ip' => '203.0.113.9',
    ]);
}

function usageJson(string $args = ''): array
{
    Artisan::call(trim("mcp:usage --json {$args}"));

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('counts calls per tool, the error rate and the top error codes', function () {
    $user = User::factory()->create(['email' => 'private@example.test']);
    foreach (range(1, 6) as $i) {
        usageRow($user, 'list_posts', 200);
    }
    usageRow($user, 'list_posts', 404);
    usageRow($user, 'list_posts', 404);
    usageRow($user, 'publish_post', 200);
    usageRow($user, 'publish_post', 402);
    usageRow($user, 'publish_post', 502);
    usageRow($user, null, 403);

    $report = usageJson();

    expect($report)->toMatchArray(['days' => 7, 'calls' => 12, 'errors' => 5, 'error_rate' => 41.7])
        ->and($report['tools'][0])->toBe([
            'tool' => 'list_posts', 'calls' => 8, 'errors' => 2, 'error_rate' => 25.0,
            'top_errors' => [['code' => 'not_found', 'status' => 404, 'count' => 2]],
        ])
        ->and(collect($report['tools'])->firstWhere('tool', 'publish_post'))->toMatchArray(['calls' => 3, 'errors' => 2, 'error_rate' => 66.7])
        ->and(collect($report['tools'])->pluck('tool')->all())->toContain('(no tool)')
        ->and($report['top_errors'][0])->toBe(['code' => 'not_found', 'status' => 404, 'count' => 2])
        ->and(collect($report['top_errors'])->pluck('code')->all())->toContain('plan_required', 'upstream_unavailable', 'forbidden_or_scope');
});

it('only reads the window asked for', function () {
    $user = User::factory()->create();
    usageRow($user, 'list_posts', 200);
    usageRow($user, 'list_posts', 500, ['created_at' => now()->subDays(10)]);

    expect(usageJson())->toMatchArray(['calls' => 1, 'errors' => 0, 'error_rate' => 0.0])
        ->and(usageJson('--days=30'))->toMatchArray(['days' => 30, 'calls' => 2, 'errors' => 1]);
});

it('never prints user data', function () {
    $user = User::factory()->create(['email' => 'private@example.test', 'name' => 'Private Person']);
    usageRow($user, 'list_posts', 200);
    usageRow($user, 'draft_post', 422);

    foreach (['mcp:usage', 'mcp:usage --json'] as $command) {
        Artisan::call($command);
        $out = Artisan::output();
        expect($out)->toContain('list_posts')
            ->not->toContain('private@example.test')
            ->not->toContain('Private Person')
            ->not->toContain('Secret Client Name')
            ->not->toContain('203.0.113.9')
            ->not->toContain($user->ulid);
    }
});

it('says so when nothing was called, and refuses a bad --days', function () {
    $this->artisan('mcp:usage')->expectsOutputToContain('No MCP calls in this window.')->assertSuccessful();
    $this->artisan('mcp:usage --days=0')->assertFailed();
    $this->artisan('mcp:usage --days=91')->assertFailed();
    $this->artisan('mcp:usage --days=abc')->assertFailed();
});
