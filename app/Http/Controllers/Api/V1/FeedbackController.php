<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use App\Notifications\FeedbackReceived;
use App\Services\Analytics;
use App\Support\SendConfirmation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/feedback {message, page?, kind: bug|idea|other} — tester
 * feedback. Stored, then one email to the team inbox with reply-to the
 * user. Throttled 10 an hour per user (route). Internal accounts (staff,
 * our own addresses) are flagged, not refused. Sends nothing public and
 * costs no Credits.
 */
class FeedbackController extends Controller
{
    public const INBOX = 'hello@creatif.space';

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:5000'],
            'page' => ['sometimes', 'nullable', 'string', 'max:500'],
            'kind' => ['required', 'string', Rule::in(Feedback::KINDS)],
        ]);

        $user = $request->user();
        $feedback = Feedback::create([
            'user_id' => $user->id,
            'kind' => $validated['kind'],
            'message' => trim($validated['message']),
            'page' => isset($validated['page']) ? trim((string) $validated['page']) : null,
            'source' => SendConfirmation::viaToken($request) ? 'mcp' : 'web',
            'internal' => Analytics::isInternal($user),
        ]);

        Notification::route('mail', self::INBOX)->notify(new FeedbackReceived($feedback, $user));

        return response()->json(['data' => [
            'id' => $feedback->ulid,
            'kind' => $feedback->kind,
            'received_at' => $feedback->created_at?->toIso8601String(),
        ]], 201);
    }
}
