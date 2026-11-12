<?php

use App\Contracts\AiCaptionProvider;
use App\Models\AiStyleProfile;
use App\Models\Plan;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Ai\FakeAiCaptionProvider;
use App\Services\Ai\OpenAiCaptionProvider;
use App\Support\Workspace;
use Illuminate\Support\Facades\Http;

// AI layer (q0959): POST /api/v1/ai/caption behind AI_CAPTION_ENABLED.

function aiFake(): FakeAiCaptionProvider
{
    $fake = new FakeAiCaptionProvider;
    app()->instance(AiCaptionProvider::class, $fake);

    return $fake;
}

beforeEach(fn () => config(['ai.caption.enabled' => true]));

it('is off by default and answers 404 while off', function () {
    expect(require config_path('ai.php'))
        ->caption->enabled->toBeFalse()
        ->openai->key->toBeNull()
        ->openai->caption_model->toBeNull();

    config(['ai.caption.enabled' => false]);
    $fake = aiFake();

    $this->actingAs(mediaUser('pro'))
        ->postJson('/api/v1/ai/caption', ['brief' => 'Kopi pagi'])
        ->assertNotFound();

    expect($fake->calls)->toBe([]);
});

it('gives Free the standard 402 plan_required body with feature ai_caption', function () {
    $fake = aiFake();

    $this->actingAs(mediaUser('free'))
        ->postJson('/api/v1/ai/caption', ['brief' => 'Kopi pagi'])
        ->assertStatus(402)
        ->assertJsonPath('reason', 'plan_required')
        ->assertJsonPath('code', 'plan_required')
        ->assertJsonPath('feature', 'ai_caption')
        ->assertJsonPath('required_plan', 'pro')
        ->assertJsonStructure(['message', 'billing_url', 'errors' => ['brief']]);

    expect($fake->calls)->toBe([]);
});

it('returns a caption and hashtags on Pro and Max', function (string $tier) {
    aiFake();

    $this->actingAs(mediaUser($tier))
        ->postJson('/api/v1/ai/caption', ['brief' => 'Kopi pagi', 'platform' => 'instagram'])
        ->assertOk()
        ->assertJsonPath('data.caption', 'Kopi pagi')
        ->assertJsonPath('data.hashtags', ['#Kopi', '#pagi', '#tag1', '#tag2', '#tag3']);
})->with(['pro', 'max']);

it('reads the caller style profile', function () {
    $fake = aiFake();
    $user = mediaUser('pro');
    $profile = AiStyleProfile::query()->forceCreate([
        'user_id' => $user->id,
        'tone' => 'playful',
        'emoji_use' => 'heavy',
        'hashtag_count' => 2,
        'language' => 'id',
        'sample_captions' => ['Pagi! ☕', 'Ngopi dulu 🙌'],
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/ai/caption', ['brief' => 'Kopi pagi'])
        ->assertOk()
        ->assertJsonPath('data.caption', '[playful] Kopi pagi')
        ->assertJsonPath('data.hashtags', ['#Kopi', '#pagi']);

    expect($fake->calls[0]['style']?->is($profile))->toBeTrue()
        ->and($fake->calls[0]['platform'])->toBeNull();
});

it('validates the brief and platform, and refuses viewers', function () {
    aiFake();
    $owner = mediaUser('pro');

    $this->actingAs($owner)->postJson('/api/v1/ai/caption', [])->assertJsonValidationErrors('brief');
    $this->actingAs($owner)->postJson('/api/v1/ai/caption', ['brief' => 'x', 'platform' => 'myspace'])
        ->assertJsonValidationErrors('platform');

    $team = User::factory()->create();
    $team->subscriptions()->create(['plan_id' => Plan::query()->where('key', 'team')->value('id'),
        'billing_period' => 'monthly', 'seats' => 3, 'status' => 'active']);
    $viewer = User::factory()->create();
    TeamMember::factory()->active($viewer)->create(['owner_id' => $team->id, 'role' => 'viewer']);
    Workspace::forget();
    $this->actingAs($viewer)->postJson('/api/v1/ai/caption', ['brief' => 'x'])->assertForbidden();
});

it('says 502 when the provider fails', function () {
    aiFake()->failing = true;

    $this->actingAs(mediaUser('pro'))
        ->postJson('/api/v1/ai/caption', ['brief' => 'x'])
        ->assertStatus(502);
});

it('selects the fake from config', function () {
    app()->forgetInstance(AiCaptionProvider::class);
    config(['ai.caption.provider' => 'fake']);
    expect(app(AiCaptionProvider::class))->toBeInstanceOf(FakeAiCaptionProvider::class);

    app()->forgetInstance(AiCaptionProvider::class);
    config(['ai.caption.provider' => 'openai']);
    expect(app(AiCaptionProvider::class))->toBeInstanceOf(OpenAiCaptionProvider::class);
});

describe('OpenAI provider', function () {
    beforeEach(fn () => config(['ai.openai' => [
        'key' => 'sk-test', 'base_url' => 'https://ai.test/v1/', 'caption_model' => 'model-from-config', 'timeout' => 5,
    ]]));

    it('sends the configured model and the style, and tidies the answer', function () {
        Http::fake(['ai.test/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'caption' => " Selamat pagi ☕ \n",
            'hashtags' => ['kopi', '#Kopi', '#pagi hari', 'senin', 'extra'],
        ])]]]])]);
        $user = mediaUser('pro');
        $style = AiStyleProfile::query()->forceCreate([
            'user_id' => $user->id, 'tone' => 'warm', 'emoji_use' => 'none', 'hashtag_count' => 3, 'language' => 'id',
            'sample_captions' => ['Pagi, teman!'],
        ]);

        $draft = (new OpenAiCaptionProvider)->caption('Kopi pagi', 'instagram', $style);

        expect($draft->caption)->toBe('Selamat pagi ☕')
            ->and($draft->hashtags)->toBe(['#kopi', '#pagihari', '#senin']);

        Http::assertSent(function ($r) {
            $system = $r['messages'][0]['content'];

            return $r->url() === 'https://ai.test/v1/chat/completions'
                && $r->hasHeader('Authorization', 'Bearer sk-test')
                && $r['model'] === 'model-from-config'
                && $r['store'] === false
                && $r['response_format'] === ['type' => 'json_object']
                && $r['messages'][1]['content'] === 'Kopi pagi'
                && str_contains($system, 'Tone: warm.')
                && str_contains($system, 'Use no emoji.')
                && str_contains($system, 'Give exactly 3 hashtags.')
                && str_contains($system, 'language: id')
                && str_contains($system, 'Pagi, teman!')
                && str_contains($system, 'instagram');
        });
    });

    it('answers 503 through the endpoint when the key or model is missing', function (string $missing) {
        config(["ai.openai.{$missing}" => null]);
        Http::fake();

        $this->actingAs(mediaUser('pro'))
            ->postJson('/api/v1/ai/caption', ['brief' => 'x'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'AI is not set up.');

        Http::assertNothingSent();
    })->with(['key', 'caption_model']);

    it('answers 502 on a failed or unusable answer', function ($response) {
        Http::fake(['*' => $response]);

        $this->actingAs(mediaUser('pro'))
            ->postJson('/api/v1/ai/caption', ['brief' => 'x'])
            ->assertStatus(502);
    })->with([
        'server error' => fn () => Http::response('nope', 500),
        'not json' => fn () => Http::response(['choices' => [['message' => ['content' => 'just text']]]]),
        'empty caption' => fn () => Http::response(['choices' => [['message' => ['content' => '{"caption":"","hashtags":[]}']]]]),
    ]);
});
