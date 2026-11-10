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

it('renders the slogan as styled live text under the brand in the HTML header', function () {
    config(['app.name' => 'Creatif Space', 'app.marketing_url' => 'https://creatif.space']);
    $mail = (new WaitlistOnTheList('https://creatif.space/mcp?ref=x'))->toMail(new AnonymousNotifiable);
    $html = (string) $mail->render();

    // The header: brand link, then the slogan block, before the body.
    $header = substr($html, (int) strpos($html, 'class="header"'), (int) strpos($html, 'Email Body') - (int) strpos($html, 'class="header"'));
    expect($header)->toMatch('/<a[^>]*href="https:\/\/creatif\.space"[^>]*>\s*Creatif Space\s*<\/a>/')
        ->and(strpos($header, 'Creatif Space'))->toBeLessThan(strpos($header, 'class="slogan"'))
        ->and(html_entity_decode(trim(strip_tags(substr($header, (int) strpos($header, 'class="slogan"') - 5))), ENT_QUOTES | ENT_HTML5))->toBe(SLOGAN);

    preg_match('/<div class="slogan" style="([^"]*)">(.*?)<\/div>/s', $html, $m);
    expect($m[1])->toContain("font-family: 'Lora', Georgia, serif")
        ->and($m[1])->toContain('font-weight: 600')
        ->and($m[1])->toContain('color: #0F172A')
        ->and($m[1])->toContain('text-align: center')
        ->and($m[2])->toMatch('/<span style="[^"]*background-color: #FF9F43;[^"]*color: #0F172A;[^"]*padding: 0 4px;[^"]*border-radius: 4px;[^"]*">published<\/span>/');

    // Live text, not an image; and only once (not repeated in the HTML footer).
    expect($html)->not->toContain('<img')
        ->and(substr_count(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5), SLOGAN))->toBe(1);
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
