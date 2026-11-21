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
    'min_chars' => (int) env('AI_ASSIST_MIN_CHARS', 20),

    // Credits per use by tier (Rizal 21 Nov): Standard = Sonnet, Extra = Opus.
    'credits' => [
        'standard' => (int) env('AI_ASSIST_CREDITS_STANDARD', 30),
        'extra' => (int) env('AI_ASSIST_CREDITS_EXTRA', 100),
    ],

];
