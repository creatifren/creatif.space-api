<?php

use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\User;
use App\Notifications\ApprovalLinkBound;
use App\Notifications\ApprovalMailFooter;
use App\Notifications\ApproverSignInLink;
use App\Notifications\SocialPostDecided;
use App\Notifications\WaitlistOnTheList;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;

/*
 * The approval emails' footer: line 1 Rizal's slogan at 30px as live text
 * (Lora with a web-safe stack ending in serif, "published" on orange),
 * line 2 "Approved with Creatif Space →" to the site with the
 * evergreen-footer UTM. The header (brand + small slogan) is unchanged,
 * and mails outside the approval flow get no such footer.
 */

const APPROVAL_SLOGAN = 'Get content approved & published faster.';

function approvalLink(): SocialApprovalLink
{
    $owner = new User;
    $owner->name = 'Rizal';
    $link = new SocialApprovalLink;
    $link->ulid = '01JAPPROVALFOOTERTEST00000';
    $link->title = 'November posts';
    $link->setRelation('user', $owner);

    return $link;
}

/** @return array<string, MailMessage> */
function approvalMails(): array
{
    $link = approvalLink();
    $post = new SocialPost;
    $post->caption = 'Kopi launch — new beans this week';
    $owner = $link->user;

    return [
        'approved' => (new SocialPostDecided($link, SocialPostDecided::APPROVED, 'Dewi', 1, $post))->toMail($owner),
        'approved, several' => (new SocialPostDecided($link, SocialPostDecided::APPROVED, 'Dewi', 3))->toMail($owner),
        'held' => (new SocialPostDecided($link, SocialPostDecided::HELD, 'Dewi', 1, $post, 'It goes out closer to its date.'))->toMail($owner),
        'changes asked' => (new SocialPostDecided($link, SocialPostDecided::REVISE, 'Dewi', 1, $post))->toMail($owner),
        'link bound' => (new ApprovalLinkBound($link, 'dewi@kopi.example', 'approved'))->toMail($owner),
        'client sign-in link' => (new ApproverSignInLink($link, 'https://creatif.space/a/x?signin=abc'))->toMail(new AnonymousNotifiable),
    ];
}

/** Visible text, entities decoded, &nbsp; read as a space, whitespace collapsed per line. */
function visibleText(string $html): string
{
    $text = html_entity_decode(strip_tags(str_replace(['</div>', '</p>'], "\n", $html)), ENT_QUOTES | ENT_HTML5);
    $lines = array_map(fn ($l) => trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $l))), explode("\n", $text));

    return implode("\n", array_values(array_filter($lines, fn ($l) => $l !== '')));
}

function footerBlock(string $html): string
{
    $marker = strpos($html, 'class="approval-footer"');
    expect($marker)->not->toBeFalse();
    // From the opening <table, so no half tag is left over as text.
    $start = strrpos(substr($html, 0, (int) $marker), '<table');
    expect($start)->not->toBeFalse();

    return substr($html, (int) $start, (int) strpos($html, '</table>', (int) $start) - (int) $start);
}

beforeEach(function () {
    config(['app.name' => 'Creatif Space', 'app.marketing_url' => 'https://creatif.space', 'app.frontend_url' => 'https://app.creatif.space']);
});

it('keeps the footer link and its UTM exactly', function () {
    expect(ApprovalMailFooter::URL)->toBe('https://creatif.space/?utm_source=approval-footer&utm_medium=referral&utm_campaign=evergreen-footer')
        ->and(ApprovalMailFooter::LINK)->toBe('Approved with Creatif Space →');
});

it('renders the two-line footer in every approval email, matching the snapshot', function (string $name) {
    $mail = approvalMails()[$name];
    $html = (string) $mail->render();
    $block = footerBlock($html);

    // Snapshot of what the reader sees: the slogan, then the link (+ its URL in text mail).
    [$sloganLine, $linkLine, $url] = explode("\n", trim((string) file_get_contents(base_path('tests/fixtures/mail/approval-footer.snap'))));
    expect(visibleText($block))->toBe($sloganLine."\n".$linkLine)
        ->and($sloganLine)->toBe(APPROVAL_SLOGAN)
        ->and($url)->toBe(ApprovalMailFooter::URL);

    // Line 1: live text (no image), 30px, Lora 600 with a web-safe stack ending in serif.
    preg_match('/<div class="approval-slogan" style="([^"]*)">(.*?)<\/div>/s', $block, $slogan);
    preg_match_all('/font-family:\s*([^;]+);/', $slogan[1], $families);
    expect($block)->not->toContain('<img')
        ->and($slogan[1])->toContain('font-size: 30px')
        ->and($slogan[1])->toContain('font-weight: 600')
        ->and($slogan[1])->toContain('letter-spacing: -0.02em')
        ->and($slogan[1])->toContain('color: #0F172A')
        ->and(trim((string) end($families[1])))->toBe("'Lora', Georgia, 'Times New Roman', Times, serif")
        ->and($slogan[2])->toMatch('/approved(&nbsp;|&#160;|\x{00A0})&amp;/u');

    // "published" on orange with ink text, radius .08em, cloned across lines.
    preg_match('/<span style="([^"]*)">published<\/span>/', $slogan[2], $mark);
    preg_match_all('/font-family:\s*([^;]+);/', $mark[1], $markFamilies);
    expect($mark[1])->toContain('background-color: #FF9F43')
        ->and($mark[1])->toContain('color: #0F172A')
        ->and($mark[1])->toContain('border-radius: 0.08em')
        ->and($mark[1])->toContain('box-decoration-break: clone')
        ->and(trim((string) end($markFamilies[1])))->toBe("'Lora', Georgia, 'Times New Roman', Times, serif");

    // Line 2: the link, with the UTM.
    preg_match('/<a class="approval-link" href="([^"]*)"[^>]*>(.*?)<\/a>/s', $block, $link);
    expect(html_entity_decode($link[1], ENT_QUOTES | ENT_HTML5))->toBe(ApprovalMailFooter::URL)
        ->and(html_entity_decode($link[2], ENT_QUOTES | ENT_HTML5))->toBe(ApprovalMailFooter::LINK);

    // After the body, before the ©.
    expect(strpos($html, 'class="approval-footer"'))->toBeGreaterThan((int) strpos($html, 'Email Body'))
        ->and(strpos($html, 'class="approval-footer"'))->toBeLessThan((int) strpos($html, '© '.date('Y')));

    // Header unchanged: brand link, then the 16px slogan, before the body.
    $header = substr($html, (int) strpos($html, 'class="header"'), (int) strpos($html, 'Email Body') - (int) strpos($html, 'class="header"'));
    preg_match('/<div class="slogan" style="([^"]*)">/', $header, $headerSlogan);
    expect($header)->toMatch('/<a[^>]*href="https:\/\/creatif\.space"[^>]*>\s*Creatif Space\s*<\/a>/')
        ->and($headerSlogan[1])->toContain('font-size: 16px')
        ->and(visibleText(substr($header, (int) strpos($header, 'class="slogan"') - 5)))->toBe(APPROVAL_SLOGAN);

    // The slogan twice in all: the header and this footer.
    expect(substr_count(visibleText($html), APPROVAL_SLOGAN))->toBe(2);

    // Text mail: the same two lines (link with its URL) above the ©.
    $text = (string) app(Markdown::class)->renderText($mail->markdown, $mail->data());
    $at = strpos($text, $sloganLine."\n".$linkLine.' '.$url);
    expect($at)->not->toBeFalse()
        ->and($at)->toBeLessThan((int) strpos($text, '© '.date('Y')));
})->with(['approved', 'approved, several', 'held', 'changes asked', 'link bound', 'client sign-in link']);

it('leaves mails outside the approval flow without the footer', function () {
    $mail = (new WaitlistOnTheList('https://creatif.space/mcp?ref=x'))->toMail(new AnonymousNotifiable);
    $html = (string) $mail->render();
    $text = (string) app(Markdown::class)->renderText($mail->markdown, $mail->data());

    expect($html)->not->toContain('approval-footer')
        ->and($html)->not->toContain('utm_source=approval-footer')
        ->and($text)->not->toContain(ApprovalMailFooter::LINK);
});
