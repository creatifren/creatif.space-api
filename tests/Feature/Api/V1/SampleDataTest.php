<?php

use App\Models\BlogPost;
use App\Models\ClientLabel;
use App\Models\ClientReport;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialComment;
use App\Models\SocialConversation;
use App\Models\SocialPost;
use App\Models\Space;
use App\Models\User;
use App\Support\PlanQuota;
use App\Support\SampleData;
use App\Support\SocialPostRelay;
use App\Support\Workspace;
use Illuminate\Validation\ValidationException;

/*
 * Sample data for new accounts (prototype v10, backlog 1d): seeded once,
 * badged, left out of quotas and the start steps, never sent, removed in
 * one call.
 */

beforeEach(fn () => Workspace::forget());

function sampleUser(): User
{
    $user = User::factory()->create()->fresh();
    SampleData::seed($user);

    return $user;
}

it('seeds one client, two sample accounts, 8 posts, a link, a report, a gallery and a blog post', function () {
    $user = sampleUser();

    expect(ClientLabel::query()->where('user_id', $user->id)->where('is_demo', true)->pluck('name')->all())->toBe(['Kopi Senja'])
        ->and(SocialAccount::query()->where('user_id', $user->id)->get()->filter->isSample()->count())->toBe(2)
        ->and(SocialPost::query()->where('user_id', $user->id)->where('is_demo', true)->count())->toBe(8)
        ->and(SocialApprovalLink::query()->where('user_id', $user->id)->where('is_demo', true)->first()?->posts()->count())->toBe(2)
        ->and(ClientReport::query()->where('user_id', $user->id)->where('is_demo', true)->count())->toBe(1)
        ->and(Space::query()->where('user_id', $user->id)->where('is_demo', true)->pluck('type')->sort()->values()->all())->toBe(['blog', 'gallery'])
        ->and(BlogPost::query()->where('user_id', $user->id)->where('is_demo', true)->value('status'))->toBe('waiting')
        ->and(SampleData::has($user))->toBeTrue();

    // A second call adds nothing.
    SampleData::seed($user);
    expect(SocialPost::query()->where('user_id', $user->id)->count())->toBe(8);
});

it('keeps sample data out of quotas, Post to and the start steps', function () {
    $user = sampleUser();

    expect(PlanQuota::queuedPosts($user))->toBe(0)
        ->and(PlanQuota::approvalClients($user)->count())->toBe(0)
        ->and(PlanQuota::totalUsed($user))->toBe(0)
        ->and($user->socialAccounts()->usable()->count())->toBe(0);

    $data = $this->actingAs($user)->getJson('/api/v1/me/onboarding')->assertOk()->json('data');
    expect($data['connect']['done'])->toBeFalse()
        ->and($data['draft']['done'])->toBeFalse();
});

it('badges sample rows for the app', function () {
    $user = sampleUser();

    $posts = $this->actingAs($user)->getJson('/api/v1/social-posts')->assertOk()->json('data');
    expect(collect($posts)->every(fn ($p) => $p['sample'] === true))->toBeTrue();
    $accounts = $this->actingAs($user)->getJson('/api/v1/social-accounts')->assertOk()->json('data');
    expect(collect($accounts)->pluck('sample')->unique()->values()->all())->toBe([true]);
});

it('never sends a sample post', function () {
    $user = sampleUser();
    $post = SocialPost::query()->where('user_id', $user->id)->where('is_demo', true)->firstOrFail();

    expect(fn () => SocialPostRelay::refuseSample($post))->toThrow(ValidationException::class);
});

it('opens a sample approval link for its owner only', function () {
    $user = sampleUser();
    $link = SocialApprovalLink::query()->where('user_id', $user->id)->where('is_demo', true)->firstOrFail();

    $this->getJson('/api/v1/a/'.$link->token)->assertNotFound();
});

it('says whether sample data is left, and removes all of it', function () {
    $user = sampleUser();

    $this->actingAs($user)->getJson('/api/v1/me/sample-data')->assertOk()->assertJsonPath('data.has_sample', true);
    $this->actingAs($user)->deleteJson('/api/v1/me/sample-data')->assertOk()
        ->assertJsonPath('data.has_sample', false)
        ->assertJsonPath('data.removed.posts', 8);

    expect(SampleData::has($user))->toBeFalse()
        ->and(SocialPost::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(SocialAccount::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(ClientLabel::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(Space::withTrashed()->where('user_id', $user->id)->count())->toBe(0);
});

it('v10 S: pictures on the posts, two sample DMs and two comments that answer nothing and go with the sample accounts', function () {
    $user = sampleUser();

    $posts = SocialPost::query()->where('user_id', $user->id)->where('is_demo', true)->get();
    expect($posts->filter(fn (SocialPost $p) => str_ends_with((string) ($p->media[0]['url'] ?? ''), '.jpg'))->count())->toBe(8);
    foreach ($posts as $post) {
        $file = public_path('sample/'.basename((string) $post->media[0]['url']));
        expect(is_file($file))->toBeTrue();
    }

    $feed = $this->actingAs($user)->getJson('/api/v1/inbox/feed')->assertOk()->json('data');
    expect(collect($feed)->where('type', 'dm')->count())->toBe(2)
        ->and(collect($feed)->where('type', 'comment')->count())->toBe(2)
        ->and(collect($feed)->every(fn ($i) => $i['channel']['sample'] === true))->toBeTrue();

    $dm = SocialConversation::query()->where('user_id', $user->id)->firstOrFail();
    $this->actingAs($user)->getJson("/api/v1/inbox/conversations/{$dm->ulid}")->assertOk()
        ->assertJsonPath('data.sample', true)
        ->assertJsonCount(1, 'data.messages');
    $this->actingAs($user)->postJson("/api/v1/inbox/conversations/{$dm->ulid}/messages", ['content' => 'Yes!'])
        ->assertStatus(422)->assertJsonPath('code', 'sample');
    $this->actingAs($user)->postJson("/api/v1/inbox/conversations/{$dm->ulid}/read")->assertOk();

    $comment = SocialComment::query()->where('user_id', $user->id)->firstOrFail();
    $this->actingAs($user)->postJson("/api/v1/inbox/comments/{$comment->ulid}/reply", ['content' => 'Thanks!'])
        ->assertStatus(422)->assertJsonPath('code', 'sample');

    $this->actingAs($user)->deleteJson('/api/v1/me/sample-data')->assertOk();
    expect(SocialConversation::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(SocialComment::query()->where('user_id', $user->id)->count())->toBe(0);
});
