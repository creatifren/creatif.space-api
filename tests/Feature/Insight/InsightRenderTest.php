<?php

use App\Jobs\BuildInsightReport;
use App\Models\InsightReport;
use App\Support\Insight\InsightCollector;
use App\Support\Insight\InsightImages;
use App\Support\Insight\InsightPeriod;
use App\Support\Insight\InsightRenderer;
use App\Support\Insight\InsightView;
use App\Support\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InsightFixture;

/*
 * Render smoke test: all three layouts from the fixture workspace, through
 * the real collector, Blade and Dompdf. Checks page counts, embedded brand
 * fonts and size. Set INSIGHT_SAMPLES_DIR to keep the PDFs (the samples
 * attached to the PR are made this way).
 */

beforeEach(function () {
    Workspace::forget();
    $this->travelTo(CarbonImmutable::parse(InsightFixture::NOW, config('app.timezone')));
    InsightFixture::fakeRelay();
});

function insightPdf(string $format, string $locale = 'en', ?string $notes = null, bool $withPosts = true, bool $sample = true): array
{
    ['owner' => $owner, 'accounts' => $accounts] = InsightFixture::workspace('pro', $withPosts);

    if (! $withPosts) {
        InsightFixture::fakeRelay(array_map(fn () => ['oct' => [], 'sep' => []], InsightFixture::posts()));
    }

    $data = app(InsightCollector::class)->collect($owner, collect(array_values($accounts)), InsightPeriod::month(InsightFixture::MONTH));
    $images = app(InsightImages::class);
    $thumbs = collect($data['thumbnail_sources'])->map(fn ($urls) => $images->firstImage($urls))->all();

    $view = InsightView::build($data, [
        'client_name' => 'Kopi Senja',
        'locale' => $locale,
        // No workspace logo exists in the API (an avatar is not a logo).
        'logo' => null,
        'branding' => true,
        'notes' => $notes,
        'generated_at' => now(),
        'thumbnails' => $thumbs,
    ]);

    $result = app(InsightRenderer::class)->render($format, $view);

    if ($sample && ($dir = env('INSIGHT_SAMPLES_DIR'))) {
        @mkdir($dir, 0755, true);
        file_put_contents($dir.'/kopi-senja-2026-10-'.$format.($locale !== 'en' ? '-'.$locale : '').($withPosts ? '' : '-empty').'.pdf', $result['pdf']);
    }

    return $result + ['view' => $view];
}

function pdfPageCount(string $pdf): int
{
    return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
}

it('renders the one-page report on exactly one A4 page with the brand fonts embedded', function () {
    $result = insightPdf('onepage');

    expect($result['pages'])->toBe(1)
        ->and(pdfPageCount($result['pdf']))->toBe(1)
        ->and(str_starts_with($result['pdf'], '%PDF-'))->toBeTrue()
        ->and(strlen($result['pdf']))->toBeLessThan(5 * 1024 * 1024)
        // Embedded (FontFile2) subsets of Lora 600 and Inter 500.
        ->and($result['pdf'])->toContain('/FontFile2')
        ->and($result['pdf'])->toMatch('#/BaseFont\s*/[A-Z]{6}\+Lora#')
        ->and($result['pdf'])->toMatch('#/BaseFont\s*/[A-Z]{6}\+Inter#')
        // A4 portrait: 595.28 × 841.89 pt.
        ->and($result['pdf'])->toMatch('#/MediaBox\s*\[\s*0\.?0*\s+0\.?0*\s+595\.28\d*\s+841\.89\d*\s*\]#');
});

it('renders the deck as 16:9 slides: cover, summary, workflow, one per channel, top posts, notes', function () {
    $result = insightPdf('deck', 'en', "Push the Black Cat cold brew on TikTok.\nTwo giveaways, not one.");

    // 3 + 5 channels + top posts + notes
    expect($result['pages'])->toBe(10)
        ->and(pdfPageCount($result['pdf']))->toBe(10)
        ->and($result['pdf'])->toMatch('#/MediaBox\s*\[\s*0\.?0*\s+0\.?0*\s+960\.?0*\s+540\.?0*\s*\]#')
        ->and($result['pdf'])->toMatch('#/BaseFont\s*/[A-Z]{6}\+Lora#')
        ->and(strlen($result['pdf']))->toBeLessThan(5 * 1024 * 1024);
});

it('leaves the notes slide out when there are no notes', function () {
    expect(insightPdf('deck', sample: false)['pages'])->toBe(9);
});

it('renders the detailed report: cover, summary, one page per channel, method notes', function (string $locale) {
    $result = insightPdf('details', $locale, $locale === 'id' ? 'Fokus ke TikTok bulan depan.' : 'Focus on TikTok next month.');

    expect($result['pages'])->toBe(8)
        ->and(pdfPageCount($result['pdf']))->toBe(8)
        ->and($result['pdf'])->toMatch('#/BaseFont\s*/[A-Z]{6}\+Lora#')
        ->and($result['pdf'])->toMatch('#/BaseFont\s*/[A-Z]{6}\+Inter#')
        ->and(strlen($result['pdf']))->toBeLessThan(5 * 1024 * 1024);
})->with(['en', 'id']);

it('prints the no-posts sentence instead of empty charts, in both languages', function () {
    $en = insightPdf('onepage', 'en', null, false);
    expect($en['pages'])->toBe(1)
        ->and($en['view']['empty'])->toBeTrue()
        ->and($en['view']['weekly'])->toBeNull()
        ->and(view('insight.onepage', $en['view'])->render())->toContain('No published posts in this period.');

    $id = insightPdf('details', 'id', null, false);
    expect(view('insight.details', $id['view'])->render())->toContain('Belum ada post terbit di periode ini.');
});

it('builds through the queued job and stores a PDF under its file name', function () {
    Storage::fake('s3');
    ['owner' => $owner, 'accounts' => $accounts] = InsightFixture::workspace();

    $report = InsightReport::create([
        'user_id' => $owner->id, 'owner_id' => $owner->id, 'client_id' => $owner->ulid,
        'format' => 'onepage', 'locale' => 'en', 'period_from' => '2026-10-01', 'period_to' => '2026-10-31',
        'options' => ['period_kind' => 'month', 'channel_ids' => collect($accounts)->pluck('ulid')->values()->all(),
            'show_logo' => true, 'hide_branding' => false, 'next_month_notes' => null],
        'status' => 'queued', 'file_name' => 'kopi-senja-2026-10-onepage.pdf',
    ]);

    dispatch_sync(new BuildInsightReport($report));

    $report->refresh();
    expect($report->status)->toBe('done')
        ->and($report->page_count)->toBe(1)
        ->and($report->size_bytes)->toBeGreaterThan(1000)
        ->and($report->expires_at)->not->toBeNull();
    Storage::disk('s3')->assertExists($report->path);
    expect(Storage::disk('s3')->get($report->path))->toStartWith('%PDF-');
});

it('leads the deck summary with one comparison line', function () {
    $en = insightPdf('deck', sample: false);
    expect($en['view']['headline'])->toBe('Reach up 70.8% vs September')
        ->and(view('insight.deck', $en['view'])->render())->toContain('Reach up 70.8% vs September');
});

it('writes the deck headline in Indonesian', function () {
    expect(insightPdf('deck', 'id', sample: false)['view']['headline'])->toBe('Jangkauan naik 70,8% dibanding September');
});

it('has no headline when there is nothing to compare with', function () {
    expect(insightPdf('deck', 'en', null, false, false)['view']['headline'])->toBeNull();
});

it('never fills the logo slot from the avatar, whatever show_logo says', function () {
    Storage::fake('s3');
    ['owner' => $owner, 'accounts' => $accounts] = InsightFixture::workspace();
    $report = InsightReport::create([
        'user_id' => $owner->id, 'owner_id' => $owner->id, 'client_id' => $owner->ulid,
        'format' => 'deck', 'locale' => 'en', 'period_from' => '2026-10-01', 'period_to' => '2026-10-31',
        'options' => ['period_kind' => 'month', 'channel_ids' => collect($accounts)->pluck('ulid')->values()->all(),
            'show_logo' => true, 'hide_branding' => false, 'next_month_notes' => null],
        'status' => 'queued', 'file_name' => 'kopi-senja-2026-10-deck.pdf',
    ]);

    dispatch_sync(new BuildInsightReport($report));

    expect($report->fresh()->status)->toBe('done');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'img.example.test'));
});

it('cover-crops thumbnails to a square and skips media that is not an image', function () {
    $images = app(InsightImages::class);

    $wide = imagecreatetruecolor(800, 400);
    ob_start();
    imagejpeg($wide);
    $uri = $images->shrink((string) ob_get_clean());
    $size = getimagesizefromstring(base64_decode(substr((string) $uri, strlen('data:image/jpeg;base64,'))));
    expect([$size[0], $size[1]])->toBe([360, 360]);

    Http::fake([
        'video.example.test/*' => Http::response('not an image', 200, ['Content-Type' => 'video/mp4']),
        'pic.example.test/*' => Http::response(InsightFixture::jpeg(1), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    expect($images->firstImage(['https://video.example.test/a.mp4', 'https://pic.example.test/b.jpg']))
        ->toStartWith('data:image/jpeg;base64,')
        ->and($images->firstImage(['https://video.example.test/a.mp4']))->toBeNull();
});
