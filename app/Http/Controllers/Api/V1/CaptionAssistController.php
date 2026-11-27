<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AiUsage;
use App\Support\CaptionStyle;
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
 * characters (AI_ASSIST_MIN_CHARS, default 10 — Rizal 21 Nov), else 422. Any
 * Claude through the Anthropic Messages API (ANTHROPIC_API_KEY, model from
 * ANTHROPIC_MODEL, default claude-opus-5-5 — Rizal, 19 Nov). No fallback to
 * another provider: a failure is a plain 502.
 * The answer comes back as a suggestion — the caption is never written here.
 *
 * Pro and Max (plans v2: feature `ai_caption`, hashtags included).
 *
 * Two tiers (Rizal 21 Nov, prototype v10.5): `quality=standard` = Sonnet
 * (services.ai.model_standard), `quality=extra` = Opus (services.ai.model).
 * "Add hashtags" costs ai_assist.credits (30 / 100); "Improve with AI" costs
 * by caption length (ai_assist.rewrite_bands: 50/100, 100/150, 150/300 — v10.24). Credits back
 * when the AI does not answer. No `quality` = standard (Rizal 21 Nov: the
 * old free Opus path is closed now that the app sends it, app #331).
 */
class CaptionAssistController extends Controller
{
    private const ANTHROPIC_VERSION = '2023-06-01';

    private const PROMPTS = [
        'rewrite' => 'Rewrite this social media caption so it reads better. Keep its language, meaning, links and hashtags. Reply with the caption only.',
        'hashtags' => 'Suggest 5 to 10 relevant hashtags for this social media caption, in its language. Reply with the hashtags only, space-separated.',
    ];

    /**
     * Output room in proportion to the caption (AI cost per tap, 21 Nov):
     * a rewrite is about as long as what was written; hashtags are short.
     */
    /**
     * Credits for one use: "Add hashtags" flat; "Improve with AI" by caption
     * length (ai_assist.rewrite_bands — up to 500 / 1,200 / 2,200 characters).
     */
    public static function price(string $mode, string $quality, string $caption): int
    {
        $flat = (int) config("ai_assist.credits.{$quality}", $quality === 'extra' ? 100 : 30);
        if ($mode !== 'rewrite') {
            return $flat;
        }
        $len = mb_strlen($caption);
        foreach ((array) config('ai_assist.rewrite_bands', []) as $upTo => $prices) {
            if ($len <= (int) $upTo) {
                return is_array($prices) ? (int) $prices[$quality === 'extra' ? 1 : 0] : $flat;
            }
        }
        $last = collect((array) config('ai_assist.rewrite_bands', []))->filter()->last();

        return is_array($last) ? (int) $last[$quality === 'extra' ? 1 : 0] : $flat;
    }

    public static function maxTokens(string $mode, string $caption): int
    {
        if ($mode === 'hashtags') {
            return 200;
        }

        return max(256, min(1024, intdiv(mb_strlen($caption), 3) + 150));
    }

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(Workspace::canWrite($request->user()), 403, 'Viewers cannot write captions.');
        PlanQuota::requireFeature($request->user(), 'ai_caption', 'mode');

        $validated = $request->validate([
            'mode' => ['required', 'in:rewrite,hashtags'],
            'caption' => ['required', 'string', 'max:'.(int) config('ai_assist.max_chars', 2200)],
            'quality' => ['sometimes', 'in:standard,extra'],
            // v10.9.2: the accounts the post goes to — a client's own Caption style wins.
            'account_ids' => ['sometimes', 'array', 'max:100'],
            'account_ids.*' => ['string', 'max:26'],
        ], [
            'caption.max' => 'AI works on captions up to :max characters — shorten it first.',
        ]);
        $quality = $validated['quality'] ?? 'standard';

        $min = max(1, (int) config('ai_assist.min_chars', 10));
        if (mb_strlen((string) preg_replace('/\s+/u', '', $validated['caption'])) < $min) {
            throw ValidationException::withMessages([
                'caption' => "Write at least {$min} characters of your caption first — AI improves and adds hashtags to what you wrote.",
            ]);
        }

        // v10.9.2 (Rizal 21 Nov): Improve with AI follows a Caption style and waits until one is set.
        $style = null;
        if ($validated['mode'] === 'rewrite') {
            if (CaptionStyle::default($request->user()) === null) {
                return response()->json([
                    'message' => 'Set your caption style first — Preferences › Caption style.',
                    'code' => 'caption_style_required',
                    'errors' => ['caption_style' => ['Set your caption style first — Preferences › Caption style.']],
                ], 422);
            }
            $style = CaptionStyle::forAccounts($request->user(), array_values($validated['account_ids'] ?? []));
        }

        $config = config('services.ai');
        abort_unless($config['key'] && $config['base_url'] && $config['model'], 503, 'AI is not set up.');
        $model = $quality === 'standard' ? (($config['model_standard'] ?? null) ?: $config['model']) : $config['model'];

        $price = self::price($validated['mode'], $quality, $validated['caption']);
        $label = ($validated['mode'] === 'rewrite' ? 'Improve with AI' : 'Add hashtags').' · '.ucfirst($quality);
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
                    'max_tokens' => self::maxTokens($validated['mode'], $validated['caption']),
                    'system' => self::PROMPTS[$validated['mode']].CaptionStyle::prompt($style),
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
        AiUsage::log('caption_'.$validated['mode'].'_'.$quality, $model, $response->json('usage'), $price);

        return response()->json(['data' => ['text' => $text, 'credits' => $price]]);
    }
}
