<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Credits;
use App\Support\PlanQuota;
use App\Support\Workspace;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * The composer's "Improve with AI" (rewrite) and "Add hashtags" — the only
 * AI path (Rizal, 13 Nov: no caption written from scratch). It works on
 * text the user already wrote: at least ai_assist.min_chars non-whitespace
 * characters (AI_ASSIST_MIN_CHARS, default 20), else 422. Any
 * Claude through the Anthropic Messages API (ANTHROPIC_API_KEY, model from
 * ANTHROPIC_MODEL, default claude-opus-5-5 — Rizal, 19 Nov). No fallback to
 * another provider: a failure is a plain 502.
 * The answer comes back as a suggestion — the caption is never written here.
 *
 * Pro and Max (plans v2: feature `ai_caption`, hashtags included).
 *
 * Two tiers (Rizal 21 Nov, prototype v10.5): `quality=standard` = Sonnet
 * (services.ai.model_standard), 30 Credits; `quality=extra` = Opus
 * (services.ai.model), 100 Credits (ai_assist.credits). Credits back
 * when the AI does not answer. No `quality` = the old path (Opus, no
 * Credits) until the app sends it.
 */
class CaptionAssistController extends Controller
{
    private const ANTHROPIC_VERSION = '2023-06-01';

    private const PROMPTS = [
        'rewrite' => 'Rewrite this social media caption so it reads better. Keep its language, meaning, links and hashtags. Reply with the caption only.',
        'hashtags' => 'Suggest 5 to 10 relevant hashtags for this social media caption, in its language. Reply with the hashtags only, space-separated.',
    ];

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(Workspace::canWrite($request->user()), 403, 'Viewers cannot write captions.');
        PlanQuota::requireFeature($request->user(), 'ai_caption', 'mode');

        $validated = $request->validate([
            'mode' => ['required', 'in:rewrite,hashtags'],
            'caption' => ['required', 'string', 'max:5000'],
            'quality' => ['sometimes', 'in:standard,extra'],
        ]);
        $quality = $validated['quality'] ?? null;

        $min = max(1, (int) config('ai_assist.min_chars', 20));
        if (mb_strlen((string) preg_replace('/\s+/u', '', $validated['caption'])) < $min) {
            throw ValidationException::withMessages([
                'caption' => "Write at least {$min} characters of your caption first — AI improves and adds hashtags to what you wrote.",
            ]);
        }

        $config = config('services.ai');
        abort_unless($config['key'] && $config['base_url'] && $config['model'], 503, 'AI is not set up.');
        $model = $quality === 'standard' ? (($config['model_standard'] ?? null) ?: $config['model']) : $config['model'];

        $price = $quality === null ? 0 : (int) config("ai_assist.credits.{$quality}", $quality === 'extra' ? 100 : 30);
        $label = ($validated['mode'] === 'rewrite' ? 'Improve with AI' : 'Add hashtags').' · '.ucfirst((string) $quality);
        $parts = Credits::chargeAi($request->user(), $price, $label, 'quality');
        $fail = function () use ($request, $parts, $label): never {
            Credits::returnAi($request->user(), $parts, $label.' · no answer');
            abort(502, 'The AI did not answer — try again.');
        };

        $response = null;
        try {
            $response = Http::withHeaders([
                'x-api-key' => $config['key'],
                'anthropic-version' => self::ANTHROPIC_VERSION,
            ])
                ->timeout(30)
                ->post(rtrim($config['base_url'], '/').'/messages', [
                    'model' => $model,
                    'max_tokens' => 1024,
                    'system' => self::PROMPTS[$validated['mode']],
                    'messages' => [
                        ['role' => 'user', 'content' => $validated['caption']],
                    ],
                ]);
        } catch (ConnectionException) {
            // handled below: no response
        }

        if ($response === null) {
            $fail();
        }
        $text = trim(collect((array) $response->json('content'))
            ->where('type', 'text')
            ->pluck('text')
            ->implode(''));
        if ($response->failed() || $text === '') {
            $fail();
        }

        return response()->json(['data' => ['text' => $text, 'credits' => $price]]);
    }
}
