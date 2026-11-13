<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
 * OpenAI-compatible chat endpoint (AI_BASE_URL + AI_API_KEY + AI_MODEL).
 * The answer comes back as a suggestion — the caption is never written here.
 *
 * Pro and Max (plans v2: feature `ai_caption`, hashtags included).
 */
class CaptionAssistController extends Controller
{
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
        ]);

        $min = max(1, (int) config('ai_assist.min_chars', 20));
        if (mb_strlen((string) preg_replace('/\s+/u', '', $validated['caption'])) < $min) {
            throw ValidationException::withMessages([
                'caption' => "Write at least {$min} characters of your caption first — AI improves and adds hashtags to what you wrote.",
            ]);
        }

        $config = config('services.ai');
        abort_unless($config['key'] && $config['base_url'] && $config['model'], 503, 'AI is not set up.');

        try {
            $response = Http::withToken($config['key'])
                ->timeout(30)
                ->post(rtrim($config['base_url'], '/').'/chat/completions', [
                    'model' => $config['model'],
                    // Not kept by OpenAI for later (evals, distillation) — CTO q1550.
                    'store' => false,
                    'messages' => [
                        ['role' => 'system', 'content' => self::PROMPTS[$validated['mode']]],
                        ['role' => 'user', 'content' => $validated['caption']],
                    ],
                ]);
        } catch (ConnectionException) {
            abort(502, 'The AI did not answer — try again.');
        }

        $text = trim((string) $response->json('choices.0.message.content'));
        abort_if($response->failed() || $text === '', 502, 'The AI did not answer — try again.');

        return response()->json(['data' => ['text' => $text]]);
    }
}
