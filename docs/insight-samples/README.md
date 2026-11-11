# Insight PDF samples (fixture data)

Made from `tests/Support/InsightFixture.php` (a fictional workspace, "Kopi Senja", October 2026) by the render smoke test:

```bash
INSIGHT_SAMPLES_DIR=docs/insight-samples vendor/bin/pest tests/Feature/Insight/InsightRenderTest.php
```

| File | Layout |
| --- | --- |
| `kopi-senja-2026-10-onepage.pdf` | One page, A4 portrait, EN |
| `kopi-senja-2026-10-deck.pdf` | Deck, 16:9 (1280 × 720), EN, with "Notes for next month" |
| `kopi-senja-2026-10-details.pdf` | Details, A4, EN |
| `kopi-senja-2026-10-details-id.pdf` | Details, A4, Indonesian |
| `kopi-senja-2026-10-onepage-empty.pdf` | One page for a client with no published posts |

The fixture deliberately leaves numbers out the way networks do (Facebook: no saves; TikTok: no reach; X: no reach / impressions / saves, followers never asked; LinkedIn: feed unavailable), so every "—" and its footnote can be checked. Thumbnails are solid-colour placeholders.
