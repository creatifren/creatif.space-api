<?php

/*
|--------------------------------------------------------------------------
| AI caption help (Rizal, 13 Nov)
|--------------------------------------------------------------------------
|
| The only AI path: POST /social-captions/assist ("Improve with AI" and
| "Add hashtags") on a caption the user already wrote. The endpoint itself
| is services.ai (ANTHROPIC_API_KEY, ANTHROPIC_BASE_URL, ANTHROPIC_MODEL). Nothing writes a
| caption from scratch any more.
|
*/

return [

    // Non-whitespace characters the caption needs before AI may touch it.
    'min_chars' => (int) env('AI_ASSIST_MIN_CHARS', 10),

    // Longest caption AI takes (Instagram's own limit) — keeps a tap's cost in line with its price (21 Nov).
    'max_chars' => (int) env('AI_ASSIST_MAX_CHARS', 2200),

    // Credits per use by tier (Rizal 21 Nov): Standard = Sonnet, Extra = Opus.
    // "Add hashtags" always costs these.
    'credits' => [
        'standard' => (int) env('AI_ASSIST_CREDITS_STANDARD', 30),
        'extra' => (int) env('AI_ASSIST_CREDITS_EXTRA', 100),
    ],

    // "Improve with AI" by caption length (Rizal 21 Nov, prototype v10.9):
    // up to N characters => [standard, extra]. Rizal 27 Nov (prototype v10.24): Standard 50 / 100 / 150
    // for a margin of at least 2x (was 30 / 75 / 150).
    'rewrite_bands' => [
        500 => [50, 100],
        1200 => [100, 150],
        2200 => [150, 300],
    ],

];
