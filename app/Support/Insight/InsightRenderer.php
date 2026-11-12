<?php

namespace App\Support\Insight;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * HTML (Blade) → PDF with Dompdf: pure PHP, so the VPS needs no new system
 * package — only the PHP extensions it already runs (dom, mbstring, gd).
 * Fonts are the brand pack's TTFs (Lora 600, Inter 500), embedded as
 * subsets. The renderer never touches the network: remote loading is off
 * and every picture arrives as a data URI.
 */
class InsightRenderer
{
    /** Deck slides: 1280 × 720 px at 96 dpi = 960 × 540 pt. */
    private const DECK_PAPER = [0, 0, 960, 540];

    /**
     * @param  array<string, mixed>  $view  InsightView::build()
     * @return array{pdf: string, pages: int}
     */
    public function render(string $format, array $view): array
    {
        $cache = storage_path('framework/cache/dompdf');
        if (! is_dir($cache)) {
            @mkdir($cache, 0755, true);
        }

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsFontSubsettingEnabled(true);
        $options->setFontDir($cache);
        $options->setFontCache($cache);
        $options->setTempDir(sys_get_temp_dir());
        $options->setChroot([resource_path('insight')]);
        $options->setDpi(96);
        $options->setDefaultFont('Inter');
        $options->setDefaultMediaType('print');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('insight.'.$format, $view)->render(), 'UTF-8');
        $dompdf->setPaper($format === 'deck' ? self::DECK_PAPER : 'A4', 'portrait');
        $dompdf->render();

        $canvas = $dompdf->getCanvas();

        if ($format === 'details') {
            // "2 / 7" bottom right of every page after the cover.
            $canvas->page_script(function (int $page, int $count, $canvas, $fontMetrics): void {
                if ($page === 1) {
                    return;
                }
                $font = $fontMetrics->getFont('Inter');
                $text = $page.' / '.$count;
                $size = 8;
                $width = $fontMetrics->getTextWidth($text, $font, $size);
                $canvas->text($canvas->get_width() - 34 - $width, $canvas->get_height() - 28, $text, $font, $size, [0.353, 0.392, 0.471]);
            });
        }

        $pdf = (string) $dompdf->output(['compress' => 1]);

        return ['pdf' => $pdf, 'pages' => $canvas->get_page_count()];
    }
}
