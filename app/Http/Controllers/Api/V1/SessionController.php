<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Settings › Account & security › "Signed in on" (prototype v10, P11): the
 * browsers and the Android app signed in to this account, from the
 * database session store. The raw session id is a secret, so each row is
 * known by a hash of it.
 *
 * Signing a device out deletes its session row and turns the remember-me
 * token over, so that device's 30-day cookie stops working too. The
 * current device gets a fresh cookie at once; every other device keeps its
 * live session until it ends. Session cookie only, never a bearer token.
 */
class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $current = $this->currentId($request);

        $rows = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->orderByDesc('last_activity')
            ->limit(50)
            ->get(['id', 'user_agent', 'last_activity']);

        return response()->json(['data' => $rows->map(fn ($row) => [
            'id' => self::key((string) $row->id),
            'device' => self::device((string) ($row->user_agent ?? '')),
            'app' => str_contains((string) ($row->user_agent ?? ''), 'CreatifAndroid'),
            'last_active_at' => Carbon::createFromTimestamp((int) $row->last_activity)->toIso8601String(),
            'current' => $current !== null && hash_equals((string) $row->id, $current),
        ])->values()]);
    }

    /** Sign one other device out. */
    public function destroy(Request $request, string $key): Response
    {
        /** @var User $user */
        $user = $request->user();
        $current = $this->currentId($request);

        $ids = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->pluck('id')
            ->map(fn ($id) => (string) $id);
        $id = $ids->first(fn (string $id) => hash_equals(self::key($id), $key));

        abort_if($id === null, 404);
        abort_if($id === $current, 422, 'Use Sign out for this device.');

        DB::table(config('session.table', 'sessions'))->where('id', $id)->delete();
        $this->turnOverRememberToken($user);

        return response()->noContent();
    }

    /** "Sign out everywhere": every other device; the app then signs this one out. */
    public function destroyOthers(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $current = $this->currentId($request);

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->when($current !== null, fn ($q) => $q->where('id', '!=', $current))
            ->delete();
        $this->turnOverRememberToken($user);

        return response()->noContent();
    }

    public static function key(string $sessionId): string
    {
        return substr(hash_hmac('sha256', $sessionId, (string) config('app.key')), 0, 32);
    }

    /** "Chrome · macOS", "Android app", "Safari · iPhone". */
    public static function device(string $ua): string
    {
        if (str_contains($ua, 'CreatifAndroid')) {
            return 'Android app';
        }

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return $os === null ? $browser : $browser.' · '.$os;
    }

    private function currentId(Request $request): ?string
    {
        return $request->hasSession() ? $request->session()->getId() : null;
    }

    /** Old remember-me cookies stop working; this device gets a new one. */
    private function turnOverRememberToken(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        $guard = Auth::guard('web');
        if ($guard instanceof SessionGuard && $guard->check() && (string) $guard->id() === (string) $user->getKey()) {
            // A full row: the recaller cookie is signed with the password hash.
            $fresh = User::query()->find($user->getKey());
            if ($fresh !== null) {
                $guard->login($fresh, remember: true);
            }
        }
    }
}
