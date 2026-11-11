<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseLead;
use App\Notifications\EnterpriseContactReceived;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

/**
 * Enterprise is contact-sales only: no checkout. This stores the request
 * and emails it to sales (config services.enterprise.contact_email) through
 * the app's mailer (Resend in production).
 *
 * Auth optional — a signed-in person is linked to the row. Throttled on the
 * route; a filled honeypot (`website`) answers the same 202 and stores and
 * sends nothing.
 */
class EnterpriseContactController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (filled($request->input('website'))) {
            return response()->json(['status' => 'received'], 202);
        }

        // A number is a fine team size ("25"); store it as text either way.
        if (is_int($request->input('team_size'))) {
            $request->merge(['team_size' => (string) $request->input('team_size')]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'company' => ['required', 'string', 'max:160'],
            'team_size' => ['required', 'string', 'max:40'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $lead = EnterpriseLead::create([
            ...$validated,
            'email' => mb_strtolower(trim($validated['email'])),
            'user_id' => $request->user('sanctum')?->id,
        ]);

        Notification::route('mail', (string) config('services.enterprise.contact_email'))
            ->notify(new EnterpriseContactReceived($lead));

        return response()->json(['status' => 'received', 'id' => $lead->ulid], 202);
    }
}
