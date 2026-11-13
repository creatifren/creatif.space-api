<?php

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config(['services.ai' => [
    'key' => 'k', 'base_url' => 'https://ai.test/v1/', 'model' => 'm',
]]));

/*
 * The only AI path (Rizal, 13 Nov): "Improve with AI" (rewrite) and "Add
 * hashtags" on a caption the user already wrote. Pro and Max (plans v2,
 * feature `ai_caption`). At least ai_assist.min_chars (20) non-whitespace
 * characters, else 422.
 */
const CA_CAPTION = 'Kopi pagi di Senja, buka jam 7.';

function captionUser(): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();

    return $user;
}

it('both modes return the endpoint answer as a suggestion, store:false, the mode prompt, the caption as written', function (string $mode, string $answer, string $prompt) {
    Http::fake(['ai.test/v1/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => " {$answer} \n"]]],
    ])]);

    $this->actingAs(captionUser())
        ->postJson('/api/v1/social-captions/assist', ['mode' => $mode, 'caption' => CA_CAPTION])
        ->assertOk()
        ->assertJsonPath('data.text', $answer);

    Http::assertSent(fn (HttpRequest $r) => $r['model'] === 'm' && $r['store'] === false
        && str_starts_with($r['messages'][0]['content'], $prompt)
        && $r['messages'][1]['content'] === CA_CAPTION
        && $r->hasHeader('Authorization', 'Bearer k'));
})->with([
    'Improve with AI' => ['rewrite', 'Kopi pagi di Senja — buka jam 7!', 'Rewrite this social media caption'],
    'Add hashtags' => ['hashtags', '#kopi #pagi', 'Suggest 5 to 10 relevant hashtags'],
]);

it('needs at least 20 non-whitespace characters: 422 with a clear message, nothing sent', function (string $mode) {
    // One fake for the whole test: a second Http::fake would not replace the first.
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);
    $user = captionUser();

    // 19 letters, padded with spaces and newlines: still too short.
    $res = $this->actingAs($user)
        ->postJson('/api/v1/social-captions/assist', ['mode' => $mode, 'caption' => "  abcdefghij \n klmnopqrs  "])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('caption');
    expect($res->json('errors.caption.0'))->toBe('Write at least 20 characters of your caption first — AI improves and adds hashtags to what you wrote.');
    Http::assertNothingSent();

    // Exactly 20 is enough.
    $this->actingAs($user)
        ->postJson('/api/v1/social-captions/assist', ['mode' => $mode, 'caption' => 'abcdefghij klmnopqrst'])
        ->assertOk();
})->with(['rewrite', 'hashtags']);

it('takes the minimum from config (AI_ASSIST_MIN_CHARS)', function () {
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);
    config(['ai_assist.min_chars' => 5]);

    $this->actingAs(captionUser())->postJson('/api/v1/social-captions/assist', ['mode' => 'rewrite', 'caption' => 'abcd'])
        ->assertUnprocessable()->assertJsonValidationErrors('caption');
    $this->actingAs(captionUser())->postJson('/api/v1/social-captions/assist', ['mode' => 'rewrite', 'caption' => 'abcde'])
        ->assertOk();
});

it('says so when the endpoint fails or is not set up', function () {
    Http::fake(['*' => Http::response('nope', 500)]);
    $user = captionUser();

    $this->actingAs($user)
        ->postJson('/api/v1/social-captions/assist', ['mode' => 'rewrite', 'caption' => CA_CAPTION])
        ->assertStatus(502);

    config(['services.ai.key' => null]);
    $this->actingAs($user)
        ->postJson('/api/v1/social-captions/assist', ['mode' => 'rewrite', 'caption' => CA_CAPTION])
        ->assertStatus(503);
});

it('POST /ai/caption is gone', function () {
    $this->actingAs(captionUser())->postJson('/api/v1/ai/caption', ['brief' => 'Coffee promo'])->assertNotFound();
});
