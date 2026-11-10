<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Notifications\WaitlistConfirm;
use App\Support\ProductEvents;
use App\Support\Waitlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Public waitlists with double opt-in. join() always answers the same 202,
 * whether the address is new, known, rate limited or a bot — it never says
 * which. Nothing is stored until confirm(), and then only in Resend.
 */
class WaitlistController extends Controller
{
    /** Confirmation emails per address per day. */
    private const PER_EMAIL_DAY = 3;

    public function join(Request $request): JsonResponse
    {
        // The honeypot: a field people never see. Filled → a bot; say nothing.
        if (filled($request->input('website'))) {
            return response()->json(['status' => 'check_inbox']);
        }

        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'list' => ['required', 'string', Rule::in(Waitlist::LISTS)],
        ]);
        $email = Str::lower(trim($validated['email']));

        if (RateLimiter::attempt('waitlist-email:'.sha1($email), self::PER_EMAIL_DAY, fn () => true, 86400)) {
            $url = route('api.v1.waitlist.confirm', [
                'token' => Waitlist::token($email, $validated['list'], ProductEvents::utmFrom($request)),
            ]);
            Notification::route('mail', $email)->notify(new WaitlistConfirm($url));
        }

        return response()->json(['status' => 'check_inbox'], 202);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $page = rtrim((string) config('app.public_url'), '/').'/mcp?waitlist=';
        $entry = Waitlist::read($request->query('token'));

        if ($entry === null) {
            return redirect($page.'expired');
        }

        if (! Waitlist::subscribe($entry['email'], $entry['list'])) {
            return redirect($page.'error');
        }

        // Once per address and list, however often the link is clicked.
        if (Cache::add('waitlist-joined:'.$entry['list'].':'.hash('sha256', $entry['email']), true)) {
            ProductEvents::waitlistJoined($entry['email'], $entry['list'], $entry['utm']);
        }

        return redirect($page.'confirmed');
    }
}
