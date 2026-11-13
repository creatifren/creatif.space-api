<?php

/*
|--------------------------------------------------------------------------
| AI caption help (Rizal, 13 Nov)
|--------------------------------------------------------------------------
|
| The only AI path: POST /social-captions/assist ("Improve with AI" and
| "Add hashtags") on a caption the user already wrote. The endpoint itself
| is services.ai (AI_API_KEY, AI_BASE_URL, AI_MODEL). Nothing writes a
| caption from scratch any more.
|
*/

return [

    // Non-whitespace characters the caption needs before AI may touch it.
    'min_chars' => (int) env('AI_ASSIST_MIN_CHARS', 20),

];
