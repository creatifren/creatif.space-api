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

it('both modes ask Claude (Messages API) with the mode prompt and the caption as written, and return its text as a suggestion', function (string $mode, string $answer, string $prompt) {
    Http::fake(['ai.test/v1/messages' => Http::response([
        'content' => [['type' => 'text', 'text' => " {$answer} \n"]],
    ])]);

    $this->actingAs(captionUser())
        ->postJson('/api/v1/social-captions/assist', ['mode' => $mode, 'caption' => CA_CAPTION])
        ->assertOk()
        ->assertJsonPath('data.text', $answer);

    Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://ai.test/v1/messages'
        && $r['model'] === 'm' && $r['max_tokens'] === 1024
        && str_starts_with($r['system'], $prompt)
        && $r['messages'] === [['role' => 'user', 'content' => CA_CAPTION]]
        && $r->hasHeader('x-api-key', 'k')
        && $r->hasHeader('anthropic-version', '2023-06-01')
        && ! $r->hasHeader('Authorization'));
})->with([
    'Improve with AI' => ['rewrite', 'Kopi pagi di Senja — buka jam 7!', 'Rewrite this social media caption'],
    'Add hashtags' => ['hashtags', '#kopi #pagi', 'Suggest 5 to 10 relevant hashtags'],
]);

it('joins the text blocks and treats an answer without text as a failure', function () {
    Http::fake(['ai.test/*' => Http::sequence()
        ->push(['content' => [['type' => 'text', 'text' => 'Part one, '], ['type' => 'text', 'text' => 'part two.']]])
        ->push(['content' => []])]);
    $user = captionUser();

    $this->actingAs($user)->postJson('/api/v1/social-captions/assist', ['mode' => 'rewrite', 'caption' => CA_CAPTION])
        ->assertOk()->assertJsonPath('data.text', 'Part one, part two.');
    $this->actingAs($user)->postJson('/api/v1/social-captions/assist', ['mode' => 'rewrite', 'caption' => CA_CAPTION])
        ->assertStatus(502);
});

it('defaults to Claude Opus 5.5 on api.anthropic.com', function () {
    $src = file_get_contents(base_path('config/services.php'));
    expect($src)->toContain("env('ANTHROPIC_MODEL', 'claude-opus-5-5')")
        ->toContain("env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1')")
        ->toContain("env('ANTHROPIC_API_KEY')");
});

it('needs at least 20 non-whitespace characters: 422 with a clear message, nothing sent', function (string $mode) {
    // One fake for the whole test: a second Http::fake would not replace the first.
    Http::fake(['*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']]])]);
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
    Http::fake(['*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']]])]);
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
