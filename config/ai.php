<?php

/*
|--------------------------------------------------------------------------
| AI layer (q0959)
|--------------------------------------------------------------------------
|
| Off until AI_CAPTION_ENABLED=true: while off, POST /api/v1/ai/caption
| answers 404 as if it did not exist. No key and no model ship as defaults:
| with either missing the endpoint answers 503 "not set up".
|
| The composer's older "Rewrite with AI" (services.ai, AI_API_KEY) is
| separate and unchanged.
|
*/

return [

    'caption' => [
        'enabled' => (bool) env('AI_CAPTION_ENABLED', false),

        // openai | fake (tests, local demos: no network, no key)
        'provider' => env('AI_CAPTION_PROVIDER', 'openai'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        // Any OpenAI-compatible endpoint; the public API unless set.
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        // The model id comes from here only; no default on purpose.
        'caption_model' => env('AI_CAPTION_MODEL'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 30),
    ],

];
