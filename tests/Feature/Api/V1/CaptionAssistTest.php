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
 * feature `ai_caption`). At least ai_assist.min_chars (10) non-whitespace
 * characters, else 422.
 */
const CA_CAPTION = 'Kopi pagi di Senja, buka jam 7.';

function captionUser(): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    \App\Support\Credits::grant($user, 1_000, 'Test');

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
        && $r['model'] === 'm' && $r['max_tokens'] === \App\Http\Controllers\Api\V1\CaptionAssistController::maxTokens($mode, CA_CAPTION)
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

it('needs at least 10 non-whitespace characters: 422 with a clear message, nothing sent', function (string $mode) {
    // One fake for the whole test: a second Http::fake would not replace the first.
    Http::fake(['*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']]])]);
    $user = captionUser();

    // 9 letters, padded with spaces and newlines: still too short.
    $res = $this->actingAs($user)
        ->postJson('/api/v1/social-captions/assist', ['mode' => $mode, 'caption' => "  abcde \n fghi  "])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('caption');
    expect($res->json('errors.caption.0'))->toBe('Write at least 10 characters of your caption first — AI improves and adds hashtags to what you wrote.');
    Http::assertNothingSent();

    // Exactly 10 is enough.
    $this->actingAs($user)
        ->postJson('/api/v1/social-captions/assist', ['mode' => $mode, 'caption' => 'abcde fghij'])
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

/* Rizal 21 Nov (prototype v10.5): Standard = Sonnet 30 Credits, Extra = Opus 100 Credits; Credits back on failure. */
it('charges by tier and picks the model', function (string $quality, string $model, int $price) {
    config(['services.ai.model_standard' => 'sonnet']);
    Http::fake(['ai.test/v1/messages' => Http::response(['content' => [['type' => 'text', 'text' => 'Better caption']]])]);
    $user = captionUser();
    \App\Support\Credits::grant($user, 1_000, 'Test');
    \App\Support\Credits::renewMonthly($user);
    $before = \App\Support\Credits::total($user);

    $this->actingAs($user)
        ->postJson('/api/v1/social-captions/assist', ['mode' => 'rewrite', 'caption' => CA_CAPTION, 'quality' => $quality])
        ->assertOk()->assertJsonPath('data.credits', $price);

    expect(\App\Support\Credits::total($user))->toBe($before - $price);
    Http::assertSent(fn (HttpRequest $r) => $r['model'] === $model);
})->with([
    'standard' => ['standard', 'sonnet', 30],
    'extra' => ['extra', 'm', 100],
]);

it('gives the tier Credits back when the AI fails; no quality = standard (30)', function () {
    Http::fake(['ai.test/v1/messages' => Http::response(['error' => 'x'], 529)]);
    $user = captionUser();
    \App\Support\Credits::grant($user, 1_000, 'Test');
    \App\Support\Credits::renewMonthly($user);
    $before = \App\Support\Credits::total($user);

    $this->actingAs($user)->postJson('/api/v1/social-captions/assist', ['mode' => 'hashtags', 'caption' => CA_CAPTION, 'quality' => 'extra'])->assertStatus(502);
    $this->actingAs($user)->postJson('/api/v1/social-captions/assist', ['mode' => 'hashtags', 'caption' => CA_CAPTION])->assertStatus(502);

    expect(\App\Support\Credits::total($user))->toBe($before);
});

it('no quality is charged as standard (the free Opus path is closed)', function () {
    config(['services.ai.model_standard' => 'sonnet']);
    Http::fake(['ai.test/v1/messages' => Http::response(['content' => [['type' => 'text', 'text' => 'Better caption']]])]);
    $user = captionUser();
    \App\Support\Credits::renewMonthly($user);
    $before = \App\Support\Credits::total($user);

    $this->actingAs($user)->postJson('/api/v1/social-captions/assist', ['mode' => 'rewrite', 'caption' => CA_CAPTION])
        ->assertOk()->assertJsonPath('data.credits', 30);

    expect(\App\Support\Credits::total($user))->toBe($before - 30);
    Http::assertSent(fn (HttpRequest $r) => $r['model'] === 'sonnet');
});

it('keeps a tap\'s cost in line with its price: output room follows the caption, 2,200 characters at most (21 Nov)', function () {
    expect(\App\Http\Controllers\Api\V1\CaptionAssistController::maxTokens('hashtags', str_repeat('a', 2000)))->toBe(200)
        ->and(\App\Http\Controllers\Api\V1\CaptionAssistController::maxTokens('rewrite', 'short caption here'))->toBe(256)
        ->and(\App\Http\Controllers\Api\V1\CaptionAssistController::maxTokens('rewrite', str_repeat('a', 1500)))->toBe(650)
        ->and(\App\Http\Controllers\Api\V1\CaptionAssistController::maxTokens('rewrite', str_repeat('a', 9000)))->toBe(1024);

    Http::fake(['*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']]])]);
    $this->actingAs(captionUser())
        ->postJson('/api/v1/social-captions/assist', ['mode' => 'rewrite', 'caption' => str_repeat('kopi ', 441)])
        ->assertUnprocessable()
        ->assertJsonPath('errors.caption.0', 'AI works on captions up to 2200 characters — shorten it first.');
    Http::assertNothingSent();
});

it('logs the tokens each answer used, with its cost and price in Credits', function () {
    \Illuminate\Support\Facades\Log::spy();
    Http::fake(['ai.test/v1/messages' => Http::response([
        'content' => [['type' => 'text', 'text' => 'Better caption']],
        'usage' => ['input_tokens' => 100, 'output_tokens' => 200],
    ])]);
    $this->actingAs(captionUser())
        ->postJson('/api/v1/social-captions/assist', ['mode' => 'rewrite', 'caption' => CA_CAPTION])
        ->assertOk();

    \Illuminate\Support\Facades\Log::shouldHaveReceived('info')->withArgs(fn ($m, $ctx = []) => $m === 'ai usage'
        && $ctx['feature'] === 'caption_rewrite_standard' && $ctx['in'] === 100 && $ctx['out'] === 200);
    expect(\App\Support\AiUsage::dollars('claude-sonnet-5-5', 100, 200))->toBe(0.0022)
        ->and(\App\Support\AiUsage::dollars('claude-opus-5-5', 1_000_000, 0))->toBe(4.0)
        ->and(\App\Support\AiUsage::dollars('m', 1, 1))->toBeNull();
});
