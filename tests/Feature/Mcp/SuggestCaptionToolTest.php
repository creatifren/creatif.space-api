<?php

use App\Contracts\AiCaptionProvider;
use App\Models\User;
use App\Services\Ai\FakeAiCaptionProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
 * The route behind the MCP suggest_caption tool, reached with a bearer
 * token: POST /api/v1/ai/caption with `draft`. AI_CAPTION_ENABLED and the
 * plan gate are the controller's, the same as for the app.
 */

beforeEach(function () {
    ensurePassportKeys();
    Http::fake();
    config(['ai.caption.enabled' => true]);
});

function captionToolKey(TestCase $test, User $user, array $scopes = ['read', 'draft']): string
{
    $key = $test->actingAs($user, 'web')
        ->postJson('/api/v1/me/api-keys', ['name' => 'Caption key', 'scopes' => $scopes])
        ->assertCreated()
        ->json('data.key');
    app('auth')->forgetGuards();

    return $key;
}

function captionToolFake(): FakeAiCaptionProvider
{
    $fake = new FakeAiCaptionProvider;
    app()->instance(AiCaptionProvider::class, $fake);

    return $fake;
}

it('suggests a caption through a draft token with the fake provider', function () {
    $fake = captionToolFake();
    $key = captionToolKey($this, mediaUser('pro'));

    bearer($this, $key)->postJson('/api/v1/ai/caption', ['brief' => 'Kopi pagi', 'platform' => 'instagram'])
        ->assertOk()
        ->assertJsonPath('data.caption', 'Kopi pagi')
        ->assertJsonPath('data.hashtags', ['#Kopi', '#pagi', '#tag1', '#tag2', '#tag3']);

    expect($fake->calls)->toHaveCount(1)
        ->and($fake->calls[0]['platform'])->toBe('instagram');
});

it('needs the draft scope', function () {
    $fake = captionToolFake();
    $key = captionToolKey($this, mediaUser('pro'), ['read']);

    bearer($this, $key)->postJson('/api/v1/ai/caption', ['brief' => 'Kopi pagi'])
        ->assertForbidden()
        ->assertJsonPath('error', 'insufficient_scope');

    expect($fake->calls)->toBe([]);
});

it('answers 404 to a token while AI_CAPTION_ENABLED is off', function () {
    config(['ai.caption.enabled' => false]);
    $fake = captionToolFake();
    $key = captionToolKey($this, mediaUser('pro'));

    bearer($this, $key)->postJson('/api/v1/ai/caption', ['brief' => 'Kopi pagi'])->assertNotFound();

    expect($fake->calls)->toBe([]);
});

it('gives a Free token the 402 plan_required body', function () {
    $fake = captionToolFake();
    $key = captionToolKey($this, mediaUser('free'));

    bearer($this, $key)->postJson('/api/v1/ai/caption', ['brief' => 'Kopi pagi'])
        ->assertStatus(402)
        ->assertJsonPath('code', 'plan_required')
        ->assertJsonPath('feature', 'ai_caption');

    expect($fake->calls)->toBe([]);
});
