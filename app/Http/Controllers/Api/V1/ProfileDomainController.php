<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Handle;
use App\Models\Profile;
use App\Support\ProfileAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * v7 public profile: the custom domain (Pro + Max, CNAME → creatif.space;
 * once verified it applies to every public page of the account), the
 * host → handle lookup the public site uses, and link tap counts.
 */
class ProfileDomainController extends Controller
{
    public const CNAME_TARGET = 'creatif.space';

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->domainArray($request->user()->profile()->first())]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! ProfileAccess::paid($user)) {
            return response()->json(['message' => 'A custom domain is on Pro and Max.', 'reason' => 'plan_required', 'required_plan' => 'pro'], 402);
        }
        $profile = $user->profile()->firstOrCreate([]);
        if (is_string($request->input('domain'))) {
            $request->merge(['domain' => mb_strtolower(trim($request->input('domain')))]);
        }
        $validated = $request->validate([
            'domain' => [
                'required', 'string', 'max:253',
                'regex:/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',
                'not_regex:/(^|\.)creatif\.space$/',
                Rule::unique('profiles', 'custom_domain')->ignore($profile->id),
            ],
        ], ['domain.regex' => 'That is not a domain name, e.g. studio.example.com.', 'domain.unique' => 'That domain is connected to another account.']);

        $domain = mb_strtolower(trim($validated['domain']));
        $profile->forceFill(['custom_domain' => $domain, 'custom_domain_verified_at' => null])->save();

        return response()->json(['data' => $this->domainArray($profile)]);
    }

    /** "Check now": is the CNAME in place? */
    public function check(Request $request): JsonResponse
    {
        $profile = $request->user()->profile()->first();
        abort_if($profile?->custom_domain === null, 404);
        if (self::pointsHere($profile->custom_domain)) {
            $profile->forceFill(['custom_domain_verified_at' => $profile->custom_domain_verified_at ?? now()])->save();
        }

        return response()->json(['data' => $this->domainArray($profile)]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $profile = $request->user()->profile()->first();
        $profile?->forceFill(['custom_domain' => null, 'custom_domain_verified_at' => null])->save();

        return response()->json(null, 204);
    }

    /** The public site (and the edge) map a verified custom host to its handle. */
    public function lookup(string $host): JsonResponse
    {
        $profile = Profile::query()
            ->where('custom_domain', mb_strtolower($host))
            ->whereNotNull('custom_domain_verified_at')
            ->with('user')
            ->first();
        abort_if($profile === null || $profile->user === null || $profile->user->status !== UserStatus::Active, 404);
        abort_unless(ProfileAccess::paid($profile->user), 404);
        $handle = Handle::query()->where('user_id', $profile->user_id)->value('name');
        abort_if($handle === null, 404);

        return response()->json(['data' => ['handle' => $handle]]);
    }

    /** One tap on a profile link (contact button, page, link). No sign-in; counts per day. */
    public function tap(Request $request, string $handle): JsonResponse
    {
        $validated = $request->validate(['target' => ['required', 'string', 'max:60', 'regex:/^[a-z]+(:[A-Za-z0-9_-]{1,40})?$/']]);
        $userId = Handle::query()->where('name', Handle::normalize($handle))->value('user_id');
        abort_if($userId === null, 404);

        $day = now()->toDateString();
        $updated = DB::table('link_taps')->where(['user_id' => $userId, 'target' => $validated['target'], 'day' => $day])->increment('taps');
        if ($updated === 0) {
            DB::table('link_taps')->insertOrIgnore(['user_id' => $userId, 'target' => $validated['target'], 'day' => $day, 'taps' => 1]);
        }

        return response()->json(null, 204);
    }

    /** Taps per link over the last 30 days (Profile › Public profile). */
    public function taps(Request $request): JsonResponse
    {
        $rows = DB::table('link_taps')
            ->where('user_id', $request->user()->id)
            ->where('day', '>=', now()->subDays(29)->toDateString())
            ->groupBy('target')
            ->selectRaw('target, SUM(taps) as taps')
            ->orderByDesc('taps')
            ->get();

        return response()->json(['data' => $rows->map(fn ($r) => ['target' => $r->target, 'taps' => (int) $r->taps])->all()]);
    }

    public static function pointsHere(string $domain): bool
    {
        $records = @dns_get_record($domain, DNS_CNAME) ?: [];
        foreach ($records as $r) {
            if (rtrim(mb_strtolower((string) ($r['target'] ?? '')), '.') === self::CNAME_TARGET) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function domainArray(?Profile $profile): array
    {
        return [
            'domain' => $profile?->custom_domain,
            'verified' => $profile?->custom_domain_verified_at !== null,
            'verified_at' => $profile?->custom_domain_verified_at?->toIso8601String(),
            'record' => ['type' => 'CNAME', 'name' => '@ (or www)', 'value' => self::CNAME_TARGET],
        ];
    }
}
