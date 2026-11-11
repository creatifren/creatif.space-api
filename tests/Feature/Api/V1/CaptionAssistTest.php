<?php

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config(['services.ai' => [
    'key' => 'k', 'base_url' => 'https://ai.test/v1/', 'model' => 'm',
]]));

// AI captions are Pro and Max (plans v2, feature `ai_caption`).
function captionUser(): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();

    return $user;
}

it('returns the endpoint answer as a suggestion', function () {
    Http::fake(['ai.test/v1/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => " #kopi #pagi \n"]]],
    ])]);

    $this->actingAs(captionUser())
        ->postJson('/api/v1/social-captions/assist', ['mode' => 'hashtags', 'caption' => 'Kopi pagi'])
        ->assertOk()
        ->assertJsonPath('data.text', '#kopi #pagi');

    Http::assertSent(fn ($r) => $r['model'] === 'm' && $r['messages'][1]['content'] === 'Kopi pagi'
        && $r->hasHeader('Authorization', 'Bearer k'));
});

it('says so when the endpoint fails or is not set up', function () {
    Http::fake(['*' => Http::response('nope', 500)]);
    $user = captionUser();

    $this->actingAs($user)
        ->postJson('/api/v1/social-captions/assist', ['mode' => 'rewrite', 'caption' => 'x'])
        ->assertStatus(502);

    config(['services.ai.key' => null]);
    $this->actingAs($user)
        ->postJson('/api/v1/social-captions/assist', ['mode' => 'rewrite', 'caption' => 'x'])
        ->assertStatus(503);
});
