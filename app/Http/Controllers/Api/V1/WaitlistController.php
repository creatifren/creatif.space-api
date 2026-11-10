<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Notifications\WaitlistConfirm;
use App\Support\ProductEvents;
use App\Support\Waitlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    private const UTM_KEYS = ['source', 'medium', 'campaign', 'term', 'content'];

    /**
     * utm_* by key: the nested `utm` object first, top-level utm_* where it
     * has none.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, string>
     */
    private static function utm(array $validated): array
    {
        $nested = is_array($validated['utm'] ?? null) ? $validated['utm'] : [];
        $out = [];
        foreach (self::UTM_KEYS as $k) {
            $value = $nested[$k] ?? $validated["utm_{$k}"] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $out["utm_{$k}"] = trim($value);
            }
        }

        return $out;
    }

    public function join(Request $request): JsonResponse
    {
        // The honeypot: a field people never see. Filled → a bot; say nothing.
        if (filled($request->input('website'))) {
            return response()->json(['status' => 'check_inbox']);
        }

        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'list' => ['required', 'string', Rule::in(Waitlist::LISTS)],
            'ref' => ['nullable', 'string', 'regex:'.Waitlist::REF_PATTERN],
            // First-touch UTMs: the marketing form sends them nested
            // (utm.source …); top-level utm_* stays as a fallback.
            'utm' => ['nullable', 'array'],
            ...collect(self::UTM_KEYS)->flatMap(fn (string $k) => [
                "utm.{$k}" => ['nullable', 'string', 'max:100'],
                "utm_{$k}" => ['nullable', 'string', 'max:100'],
            ])->all(),
        ]);
        $email = Str::lower(trim($validated['email']));

        if (RateLimiter::attempt('waitlist-email:'.sha1($email), self::PER_EMAIL_DAY, fn () => true, 86400)) {
            $url = route('api.v1.waitlist.confirm', [
                'token' => Waitlist::token($email, $validated['list'], self::utm($validated), $validated['ref'] ?? null),
            ]);
            Notification::route('mail', $email)->notify(new WaitlistConfirm($url));
        }

        return response()->json(['status' => 'check_inbox'], 202);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $page = rtrim((string) config('app.public_url'), '/').'/mcp?';
        $entry = Waitlist::read($request->query('token'));

        if ($entry === null) {
            return redirect($page.http_build_query(['waitlist' => 'expired']));
        }

        $done = Waitlist::confirm($entry);
        if ($done === null) {
            return redirect($page.http_build_query(['waitlist' => 'error']));
        }

        if ($done['first']) {
            ProductEvents::waitlistJoined($entry['email'], $entry['list'], $done['referred'], $entry['ref'] !== null, $entry['utm']);
        }

        return redirect($page.http_build_query(['waitlist' => 'confirmed', 'code' => $done['code']]));
    }

    /** Referral progress for the share box ("1 of 3 confirmed"). No PII. */
    public function status(Request $request): JsonResponse
    {
        $code = $request->string('code')->toString();
        $status = preg_match(Waitlist::REF_PATTERN, $code) ? Waitlist::status($code) : null;

        return $status === null
            ? response()->json(['message' => 'Unknown code.'], 404)
            : response()->json($status + ['needed' => Waitlist::PRIORITY_AT]);
    }
}
