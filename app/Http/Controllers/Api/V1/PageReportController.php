<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Handle;
use App\Notifications\PageReported;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/public/report-page {url, reason, website?} — "Report this
 * page" on Free public pages (anti-spam, Rizal 24 Nov, prototype v10.21).
 *
 * No sign-in. Nothing is stored: one email to the team inbox and one log
 * line. The owner is not told who reported. A filled honeypot (`website`)
 * answers the same 202 and sends nothing; the route throttle keeps it from
 * being a spam relay.
 */
class PageReportController extends Controller
{
    public const REASONS = ['Spam', 'Scam or fake', 'Uses my work without permission', 'Something else'];

    public function __invoke(Request $request): JsonResponse
    {
        if (filled($request->input('website'))) {
            return response()->json(['status' => 'received'], 202);
        }

        $validated = $request->validate([
            'url' => ['required', 'string', 'max:500', 'regex:#^https://(app\.)?creatif\.space/#'],
            'reason' => ['required', 'string', Rule::in(self::REASONS)],
        ]);

        $path = (string) parse_url($validated['url'], PHP_URL_PATH);
        $first = explode('/', trim($path, '/'))[0];
        $handle = $first !== '' && $first !== 'a' ? Handle::query()->where('name', mb_strtolower($first))->first() : null;
        $owner = $handle?->user;

        Log::warning('page reported', ['url' => $validated['url'], 'reason' => $validated['reason'], 'owner_id' => $owner?->id]);
        Notification::route('mail', FeedbackController::INBOX)
            ->notify(new PageReported($validated['url'], $validated['reason'], $owner?->email, $owner?->id));

        return response()->json(['status' => 'received'], 202);
    }
}
