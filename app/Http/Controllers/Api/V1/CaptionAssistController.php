<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Workspace;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * The composer's "Rewrite with AI" and "Generate hashtags". Any
 * OpenAI-compatible chat endpoint (AI_BASE_URL + AI_API_KEY + AI_MODEL).
 * The answer comes back as a suggestion — the caption is never written here.
 *
 * ponytail: free and throttled; charge Credits here if usage turns costly.
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

        $validated = $request->validate([
            'mode' => ['required', 'in:rewrite,hashtags'],
            'caption' => ['required', 'string', 'max:5000'],
        ]);

        $config = config('services.ai');
        abort_unless($config['key'] && $config['base_url'] && $config['model'], 503, 'AI is not set up.');

        try {
            $response = Http::withToken($config['key'])
                ->timeout(30)
                ->post(rtrim($config['base_url'], '/').'/chat/completions', [
                    'model' => $config['model'],
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
