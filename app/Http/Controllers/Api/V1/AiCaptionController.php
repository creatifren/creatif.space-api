<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\AiCaptionProvider;
use App\Enums\SocialPlatform;
use App\Http\Controllers\Controller;
use App\Models\AiStyleProfile;
use App\Support\PlanQuota;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/ai/caption — a caption and hashtags from a brief, written in
 * the caller's style profile. A suggestion only: nothing is saved.
 *
 * Behind AI_CAPTION_ENABLED (404 while off). Pro and Max: Free gets the
 * standard 402 plan_required body, feature `ai_caption`.
 */
class AiCaptionController extends Controller
{
    public function __invoke(Request $request, AiCaptionProvider $ai): JsonResponse
    {
        abort_unless((bool) config('ai.caption.enabled'), 404);
        abort_unless(Workspace::canWrite($request->user()), 403, 'Viewers cannot write captions.');
        PlanQuota::requireFeature($request->user(), 'ai_caption', 'brief');

        $validated = $request->validate([
            'brief' => ['required', 'string', 'max:2000'],
            'platform' => ['sometimes', 'nullable', Rule::enum(SocialPlatform::class)],
        ]);

        $style = AiStyleProfile::query()->where('user_id', $request->user()->id)->first();
        $draft = $ai->caption($validated['brief'], $validated['platform'] ?? null, $style);

        return response()->json(['data' => [
            'caption' => $draft->caption,
            'hashtags' => $draft->hashtags,
        ]]);
    }
}
