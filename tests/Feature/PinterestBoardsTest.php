<?php

use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Analytics;
use App\Support\Credits;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/*
 * Bug b202611140812 (18 Nov): a Pin sent through a connected app failed with
 * missing_board and nothing could name a board. A board given for a
 * Pinterest channel (`options.{account}.board_id` on create, update and
 * send) must be one of that account's boards, and is remembered as the
 * channel's default: the next Pin that names none goes there.
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
    ]);
    Cache::flush();
    Notification::fake();
    Analytics::fake();
});

function pbUser(): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    Credits::grant($user, 30_000, 'Test');

    return $user;
}

function pbPinterest(User $user): SocialAccount
{
    return SocialAccount::factory()->for($user)->create(['platform' => 'pinterest', 'provider' => 'outstand']);
}

/** Outstand: two boards for every Pinterest account, and posts accepted. */
function pbFakeOutstand(array $boards = [['id' => 'b1', 'name' => 'Recipes'], ['id' => 'b2', 'name' => 'Travel']]): void
{
    Http::fake([
        'api.outstand.test/v1/pinterest/accounts/*/boards' => Http::response(['success' => true, 'data' => $boards]),
        'api.outstand.test/v1/posts' => Http::response(['success' => true, 'post' => ['id' => 'op_pin', 'publishedAt' => null]]),
    ]);
}

function pbSentBoard(): ?string
{
    $sent = Http::recorded(fn (HttpRequest $r) => $r->url() === 'https://api.outstand.test/v1/posts')->last();

    return $sent === null ? null : ($sent[0]['pinterest']['board_id'] ?? null);
}

const PB_MEDIA = [['url' => 'https://cdn.example.test/a.jpg']];

it('refuses a board that is not one of the account’s boards, naming the field and where to look', function () {
    pbFakeOutstand();
    $user = pbUser();
    $pin = pbPinterest($user);

    $this->actingAs($user)->postJson('/api/v1/social-posts', [
        'caption' => 'Pin it', 'account_ids' => [$pin->ulid], 'media' => PB_MEDIA,
        'options' => [$pin->ulid => ['board_id' => 'not-mine']],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(["options.{$pin->ulid}.board_id"]);

    expect(SocialPost::query()->count())->toBe(0)
        ->and($pin->fresh()->pinterest_default_board_id)->toBeNull();
});

it('sends the chosen board and remembers it; the next Pin that names none goes there', function () {
    pbFakeOutstand();
    $user = pbUser();
    $pin = pbPinterest($user);

    $this->actingAs($user)->postJson('/api/v1/social-posts', [
        'caption' => 'First', 'account_ids' => [$pin->ulid], 'media' => PB_MEDIA,
        'options' => [$pin->ulid => ['board_id' => 'b2']],
    ])->assertCreated();
    expect(pbSentBoard())->toBe('b2')
        ->and($pin->fresh()->pinterest_default_board_id)->toBe('b2');

    // Several boards, none named: the remembered one, not missing_board.
    $this->postJson('/api/v1/social-posts', [
        'caption' => 'Second', 'account_ids' => [$pin->ulid], 'media' => PB_MEDIA,
    ])->assertCreated();
    expect(pbSentBoard())->toBe('b2');

    $this->getJson("/api/v1/social-accounts/{$pin->ulid}/pinterest-boards")
        ->assertOk()->assertJsonPath('meta.default_board_id', 'b2')->assertJsonCount(2, 'data');
});

it('asks again (missing_board) when the remembered board is gone from the account', function () {
    pbFakeOutstand();
    $user = pbUser();
    $pin = pbPinterest($user);
    $pin->forceFill(['pinterest_default_board_id' => 'deleted-board'])->save();

    $this->actingAs($user)->postJson('/api/v1/social-posts', [
        'caption' => 'Pin it', 'account_ids' => [$pin->ulid], 'media' => PB_MEDIA,
    ])->assertUnprocessable()->assertJsonPath('problems.0.code', 'missing_board');
});

it('sets the board on a draft (PATCH options), checks it, and the send uses it', function () {
    pbFakeOutstand();
    $user = pbUser();
    $pin = pbPinterest($user);

    $id = $this->actingAs($user)->postJson('/api/v1/social-posts', [
        'caption' => 'Draft pin', 'account_ids' => [$pin->ulid], 'media' => PB_MEDIA, 'draft' => true,
    ])->assertCreated()->json('data.id');

    // Without a board the send is refused, before anything is charged.
    $this->postJson("/api/v1/social-posts/{$id}/send")
        ->assertUnprocessable()->assertJsonPath('problems.0.code', 'missing_board');

    $this->patchJson("/api/v1/social-posts/{$id}", ['options' => [$pin->ulid => ['board_id' => 'nope']]])
        ->assertUnprocessable()->assertJsonValidationErrors(["options.{$pin->ulid}.board_id"]);
    $this->patchJson("/api/v1/social-posts/{$id}", ['options' => ['01JNOTONTHISPOSTNOTONTHIS1' => ['board_id' => 'b1']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['options']);

    $this->patchJson("/api/v1/social-posts/{$id}", ['options' => [$pin->ulid => ['board_id' => 'b1']]])->assertOk();
    $post = SocialPost::query()->where('ulid', $id)->sole();
    expect($post->targets()->sole()->configuration['board_id'])->toBe('b1')
        ->and($post->status->value)->toBe('draft')
        ->and($pin->fresh()->pinterest_default_board_id)->toBe('b1');

    $this->postJson("/api/v1/social-posts/{$id}/send")->assertOk();
    expect(pbSentBoard())->toBe('b1');
});

it('takes the board on send itself (the REST twin of publish_post with pinterest.board_id)', function () {
    pbFakeOutstand();
    $user = pbUser();
    $pin = pbPinterest($user);

    $id = $this->actingAs($user)->postJson('/api/v1/social-posts', [
        'caption' => 'Draft pin', 'account_ids' => [$pin->ulid], 'media' => PB_MEDIA, 'draft' => true,
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/social-posts/{$id}/send", ['options' => [$pin->ulid => ['board_id' => 'nope']]])
        ->assertUnprocessable()->assertJsonValidationErrors(["options.{$pin->ulid}.board_id"]);
    Http::assertNotSent(fn (HttpRequest $r) => $r->url() === 'https://api.outstand.test/v1/posts');

    $this->postJson("/api/v1/social-posts/{$id}/send", ['options' => [$pin->ulid => ['board_id' => 'b2']]])->assertOk();
    expect(pbSentBoard())->toBe('b2')
        ->and($pin->fresh()->pinterest_default_board_id)->toBe('b2');
});

it('does not change the board of a post that already went to the provider', function () {
    pbFakeOutstand();
    $user = pbUser();
    $pin = pbPinterest($user);

    $id = $this->actingAs($user)->postJson('/api/v1/social-posts', [
        'caption' => 'Out', 'account_ids' => [$pin->ulid], 'media' => PB_MEDIA,
        'options' => [$pin->ulid => ['board_id' => 'b1']],
    ])->assertCreated()->json('data.id');

    $this->patchJson("/api/v1/social-posts/{$id}", ['options' => [$pin->ulid => ['board_id' => 'b2']]])
        ->assertStatus(409)->assertJsonPath('code', 'not_editable');
    expect(SocialPost::query()->where('ulid', $id)->sole()->targets()->sole()->configuration['board_id'])->toBe('b1');
});

it('takes a board as given when the relay cannot list boards', function () {
    Http::fake([
        'api.outstand.test/v1/pinterest/accounts/*/boards' => Http::response(['success' => false], 500),
        'api.outstand.test/v1/posts' => Http::response(['success' => true, 'post' => ['id' => 'op_pin', 'publishedAt' => null]]),
    ]);
    $user = pbUser();
    $pin = pbPinterest($user);

    $this->actingAs($user)->postJson('/api/v1/social-posts', [
        'caption' => 'Pin it', 'account_ids' => [$pin->ulid], 'media' => PB_MEDIA,
        'options' => [$pin->ulid => ['board_id' => 'b7']],
    ])->assertCreated();
    expect(pbSentBoard())->toBe('b7');
});
