<?php

use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialApprovalDecision;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Support\ApprovalConfirmation;
use App\Support\Credits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * MCP approver side: list_waiting_approvals / decide_approval over
 * /me/approvals/*, scope approvals:decide, same decision path as /a,
 * audited as source "mcp".
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
    ensurePassportKeys();
});

/** An owner with a held post on a link sent to rani@client.test (or an old unbound link). */
function maLink(bool $paid = true, ?string $approver = 'rani@client.test', string $at = '2026-11-22 03:00:00'): array
{
    $owner = User::factory()->create();
    if ($paid) {
        Subscription::factory()->for($owner)->create();
    }
    Credits::grant($owner, 50_000, 'Test');
    $post = SocialPost::factory()->for($owner)->create([
        'provider_post_id' => null, 'status' => 'scheduled', 'approval' => 'client',
        'scheduled_at' => CarbonImmutable::parse($at, 'UTC'), 'caption' => 'Launch day',
    ]);
    $post->targets()->create(['social_account_id' => SocialAccount::factory()->for($owner)->create()->id, 'status' => 'pending']);
    $link = SocialApprovalLink::create(['user_id' => $owner->id, 'title' => 'December', 'approver_email' => $approver]);
    $link->posts()->attach($post->id);

    return [$owner, $post, $link];
}

/** An API key for the approver (verified email), with these scopes. */
function maKey(TestCase $test, string $email = 'rani@client.test', array $scopes = ['read', 'approvals:decide']): string
{
    $user = User::query()->where('email', $email)->first() ?? User::factory()->create(['email' => $email]);
    $key = $test->actingAs(User::query()->findOrFail($user->id), 'web')
        ->postJson('/api/v1/me/api-keys', ['name' => 'Claude', 'scopes' => $scopes])
        ->assertCreated()->json('data.key');
    app('auth')->forgetGuards();

    return $key;
}

function maPreview(TestCase $test, string $key, SocialApprovalLink $link, SocialPost $post, string $decision = 'approve', ?string $reason = null)
{
    return bearer($test, $key)->getJson("/api/v1/me/approvals/{$post->ulid}/preview?".http_build_query(array_filter([
        'link' => $link->ulid, 'decision' => $decision, 'reason' => $reason,
    ])));
}

function maDecide(TestCase $test, string $key, SocialApprovalLink $link, SocialPost $post, string $token, string $decision = 'approve', ?string $reason = null)
{
    return bearer($test, $key)->postJson("/api/v1/me/approvals/{$post->ulid}/decision", array_filter([
        'link' => $link->ulid, 'decision' => $decision, 'reason' => $reason, 'confirm_token' => $token,
    ]));
}

it('adds approvals:decide to the scopes, with its own line on the consent screen', function () {
    expect(config('mcp.scopes'))->toHaveKey('approvals:decide')
        ->and(config('mcp.scopes.approvals:decide'))->toBe('Approve or request changes on posts sent to you');

    $client = mcpClient();
    [, $challenge] = pkcePair();
    $this->actingAs(User::factory()->create(), 'web')
        ->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $client->redirect_uris[0],
            'response_type' => 'code',
            'scope' => 'read approvals:decide',
            'state' => 's',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]))
        ->assertOk()
        ->assertSee('Decide approvals')
        ->assertSee('Approve or request changes on posts sent to you');
});

it('lists what is waiting on the approver for a key with approvals:decide, and refuses a key without it', function () {
    [$owner, $post, $link] = maLink();

    bearer($this, maKey($this))->getJson('/api/v1/me/approvals/waiting')
        ->assertOk()
        ->assertJsonPath('data.0.link.id', $link->ulid)
        ->assertJsonPath('data.0.link.editor', $owner->name)
        ->assertJsonPath('data.0.posts.0.id', $post->ulid);

    bearer($this, maKey($this, 'rani@client.test', ['read']))->getJson('/api/v1/me/approvals/waiting')
        ->assertForbidden()->assertJsonPath('error', 'insufficient_scope');
});

it('previews, then approves with the confirm_token through the /a decision path, audited as mcp', function () {
    [$owner, $post, $link] = maLink();
    $key = maKey($this);

    $preview = maPreview($this, $key, $link, $post)->assertOk()
        ->assertJsonPath('data.post.id', $post->ulid)
        ->assertJsonPath('data.post.caption', 'Launch day')
        ->assertJsonPath('data.decidable', true)
        ->assertJsonPath('data.already', false)
        ->json('data');
    expect($preview['confirm_token'])->toBeString();
    expect($post->fresh()->approval->value)->toBe('client'); // a preview decides nothing

    maDecide($this, $key, $link, $post, $preview['confirm_token'])
        ->assertOk()->assertJsonPath('data.approval', 'approved');

    $row = SocialApprovalDecision::query()->sole();
    expect($row->source)->toBe('mcp')
        ->and($row->decision)->toBe('approved')
        ->and($row->approver_email)->toBe('rani@client.test')
        ->and($row->approver_user_id)->toBe(User::query()->where('email', 'rani@client.test')->value('id'));

    // Idempotent: the same confirmed decision again changes nothing and writes no second row.
    maDecide($this, $key, $link, $post, $preview['confirm_token'])->assertOk()->assertJsonPath('data.approval', 'approved');
    expect(SocialApprovalDecision::query()->count())->toBe(1);
});

it('requests changes with a reason, which the owner reads as a comment', function () {
    [, $post, $link] = maLink();
    $key = maKey($this);

    $token = maPreview($this, $key, $link, $post, 'revise', 'Brighter photo')->assertOk()->json('data.confirm_token');
    maDecide($this, $key, $link, $post, $token, 'revise', 'Brighter photo')->assertOk()->assertJsonPath('data.approval', 'revision');

    expect($post->fresh()->comments()->first()->body)->toBe('Brighter photo')
        ->and(SocialApprovalDecision::query()->sole()->only(['decision', 'source']))->toBe(['decision' => 'changes_requested', 'source' => 'mcp']);
});

it('needs the confirm_token, and refuses one for a post that changed, a different decision or another reason', function () {
    [, $post, $link] = maLink();
    $key = maKey($this);

    bearer($this, $key)->postJson("/api/v1/me/approvals/{$post->ulid}/decision", ['link' => $link->ulid, 'decision' => 'approve'])
        ->assertUnprocessable()->assertJsonValidationErrors('confirm_token');

    $token = maPreview($this, $key, $link, $post)->json('data.confirm_token');
    maDecide($this, $key, $link, $post, $token, 'revise', 'x')->assertStatus(409)->assertJsonPath('code', 'preview_stale');

    $revise = maPreview($this, $key, $link, $post, 'revise', 'A')->json('data.confirm_token');
    maDecide($this, $key, $link, $post, $revise, 'revise', 'B')->assertStatus(409);

    // The owner edits the caption after the preview: the approver must see it again.
    $post->update(['caption' => 'Launch day — new text']);
    maDecide($this, $key, $link, $post, $token)->assertStatus(409)->assertJsonPath('code', 'preview_stale');

    // Ten minutes later a token is dead too.
    $fresh = maPreview($this, $key, $link, $post)->json('data.confirm_token');
    $this->travel(11)->minutes();
    maDecide($this, $key, $link, $post, $fresh)->assertStatus(409);

    expect($post->fresh()->approval->value)->toBe('client')
        ->and(SocialApprovalDecision::query()->count())->toBe(0);
});

it('works only for the invited approver — never someone else, never an unbound link', function () {
    [, $post, $link] = maLink();
    [, $oldPost, $old] = maLink(approver: null);
    $other = maKey($this, 'someone@else.test');

    maPreview($this, $other, $link, $post)->assertForbidden()
        ->assertJson(['reason' => 'approver_mismatch', 'expected_hint' => 'r***@c***.test']);
    $forged = ApprovalConfirmation::issue(User::query()->where('email', 'someone@else.test')->sole(), $link, $post, 'approve', null);
    maDecide($this, $other, $link, $post, $forged)->assertForbidden();

    // A link made before signed-in approvers is bound on the /a page, never from MCP.
    maPreview($this, maKey($this), $old, $oldPost)->assertForbidden()->assertJsonPath('expected_hint', null);
    expect($old->fresh()->approver_email)->toBeNull();
});

it('accepts the link by URL too, and 404s a post that is not on the link', function () {
    [, $post, $link] = maLink();
    [, $elsewhere] = maLink();
    $key = maKey($this);

    bearer($this, $key)->getJson("/api/v1/me/approvals/{$post->ulid}/preview?".http_build_query(['link' => $link->url(), 'decision' => 'approve']))
        ->assertOk()->assertJsonPath('data.link.id', $link->ulid);
    maPreview($this, $key, $link, $elsewhere)->assertNotFound();

    // No link, or an unknown one: 404, like any id this caller cannot reach.
    bearer($this, $key)->getJson("/api/v1/me/approvals/{$post->ulid}/preview?decision=approve")->assertNotFound();
    bearer($this, $key)->postJson("/api/v1/me/approvals/{$post->ulid}/decision", ['link' => (string) str()->ulid(), 'decision' => 'approve', 'confirm_token' => 'x'])->assertNotFound();
});

it('applies Free\'s 7-day window: no confirm step for a post further out, and the API refuses it', function () {
    [, $post, $link] = maLink(paid: false, at: '2026-11-29 03:00:00');
    $key = maKey($this);

    maPreview($this, $key, $link, $post)->assertOk()
        ->assertJsonPath('data.decidable', false)
        ->assertJsonPath('data.decidable_from', '2026-11-22T03:00:00Z')
        ->assertJsonPath('data.confirm_token', null);

    $token = ApprovalConfirmation::issue(User::query()->where('email', 'rani@client.test')->sole(), $link, $post, 'approve', null);
    maDecide($this, $key, $link, $post, $token)
        ->assertUnprocessable()->assertJson(['reason' => 'outside_window', 'decidable_from' => '2026-11-22T03:00:00Z']);
});

it('keeps the /a page audited as web', function () {
    [, $post, $link] = maLink();

    asApprover($this, 'rani@client.test')->postJson("/api/v1/a/{$link->token}/posts/{$post->ulid}/decision", ['decision' => 'approve'])->assertOk();

    expect(SocialApprovalDecision::query()->sole()->source)->toBe('web');
});
