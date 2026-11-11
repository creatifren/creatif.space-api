<?php

/*
|--------------------------------------------------------------------------
| Media v2 (founder decision, 11 Nov 2026)
|--------------------------------------------------------------------------
|
| Every media limit lives here, in its own file, apart from the plans table
| and PlanSeeder (Plans v2 owns those). Read through App\Support\Media\MediaLimits;
| nothing else should reach into this config.
|
| Tiers: `free`, `pro`, `max`. A trial counts as `pro` whatever plan it is
| for; Team (not sold) counts as `pro`.
|
*/

$gb = 1024 * 1024 * 1024;
$mb = 1024 * 1024;

return [

    /*
     * report (default): the retention job only logs and summarises what it
     * WOULD delete. delete: it deletes. Switching production to delete
     * needs the founder's OK.
     */
    'retention_mode' => env('MEDIA_RETENTION_MODE', 'report'),

    'tiers' => [
        'free' => [
            // Free has no Keep; this is the total cap on everything stored.
            'keep_quota_bytes' => 0,
            'total_bytes' => 1 * $gb,
            'video_max_bytes' => 100 * $mb,
            // Where a refusal points.
            'next_step' => 'upgrade_pro',
        ],
        'pro' => [
            'keep_quota_bytes' => 25 * $gb,
            'total_bytes' => null,
            'video_max_bytes' => 500 * $mb,
            'next_step' => 'upgrade_max',
        ],
        'max' => [
            'keep_quota_bytes' => 50 * $gb,
            'total_bytes' => null,
            // No plan cap: only the technical ceiling below applies.
            'video_max_bytes' => null,
            // More than 50 GB is Enterprise (POST /api/v1/enterprise/contact).
            'next_step' => 'contact_sales',
        ],
    ],

    'image_max_bytes' => 10 * $mb,

    /*
     * INTERNAL: the hard ceiling for any single object. Never shown to
     * users, never in an error message.
     */
    'technical_max_bytes' => (int) env('MEDIA_TECHNICAL_MAX_BYTES', 5 * $gb),

    // PDFs and SVGs (Spaces material, not post media) keep the old ceiling.
    'document_max_bytes' => 500 * $mb,

    'retention' => [
        // Paid: a library file goes this long after the last post using it published.
        'paid_after_publish_days' => 30,
        // Free: a file attached to no queued post goes after this long.
        'free_unattached_hours' => 24,
        // A failed post is retryable for this long after its slot.
        'failed_retry_days' => 7,
        // Downgrade to Free: kept files stay this long after the downgrade
        // (/terms), with emails this many days before the first deletion.
        'downgrade_keep_days' => 30,
        'downgrade_notice_days' => [7, 1],
        // report (default): the notice job logs whom it would email;
        // send: it emails. Turn on together with retention delete mode.
        'downgrade_notices' => env('MEDIA_DOWNGRADE_NOTICES', 'report'),
        // Users per chunk.
        'chunk' => 100,
    ],

    /*
     * INTERNAL abuse limits. Never in public copy or docs; the only thing a
     * user ever sees is "Upload limit reached, try again tomorrow".
     */
    'abuse' => [
        'daily_bytes' => (int) env('MEDIA_DAILY_UPLOAD_BYTES', 20 * $gb),
        'daily_count' => (int) env('MEDIA_DAILY_UPLOAD_COUNT', 500),
        'presign_per_minute' => (int) env('MEDIA_PRESIGN_PER_MINUTE', 30),
        // Cap hits within the window that flag an account for review.
        'flag_after_hits' => (int) env('MEDIA_FLAG_AFTER_HITS', 5),
        'flag_window_days' => 7,
    ],

];
