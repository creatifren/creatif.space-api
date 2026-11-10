<?php

use App\Notifications\WaitlistOnTheList;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\AnonymousNotifiable;

/*
 * Rizal's slogan, verbatim, in every transactional email and in the
 * protected-resource metadata's docs link.
 */

const SLOGAN = 'Get content approved & published faster.';

it('keeps the slogan exactly as Rizal wrote it', function () {
    expect(config('app.slogan'))->toBe(SLOGAN);
});

it('puts the slogan under the © in the HTML mail footer, header stays the brand', function () {
    config(['app.name' => 'Creatif Space', 'app.marketing_url' => 'https://creatif.space']);
    $mail = (new WaitlistOnTheList('https://creatif.space/mcp?ref=x'))->toMail(new AnonymousNotifiable);
    $html = html_entity_decode((string) $mail->render(), ENT_QUOTES | ENT_HTML5);

    $copyright = strpos($html, '© '.date('Y'));
    expect($copyright)->not->toBeFalse()
        ->and(strpos($html, SLOGAN, (int) $copyright))->not->toBeFalse()
        ->and(substr_count($html, SLOGAN))->toBe(1)
        ->and($html)->toMatch('/<a[^>]*href="https:\/\/creatif\.space"[^>]*>\s*'.'Creatif Space'.'\s*<\/a>/');
});

it('puts the slogan under the © in the text mail footer', function () {
    $mail = (new WaitlistOnTheList('https://creatif.space/mcp?ref=x'))->toMail(new AnonymousNotifiable);
    $text = (string) app(Markdown::class)->renderText($mail->markdown, $mail->data());

    $copyright = strpos($text, '© '.date('Y'));
    expect($copyright)->not->toBeFalse()
        ->and(strpos($text, SLOGAN, (int) $copyright))->not->toBeFalse();
});

it('points protected-resource metadata at the MCP docs', function () {
    $this->getJson('/.well-known/oauth-protected-resource')
        ->assertOk()
        ->assertJsonPath('resource_documentation', 'https://creatif.space/docs/mcp');
});
