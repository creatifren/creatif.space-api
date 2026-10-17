<?php

namespace App\Support;

use App\Models\Handle;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Onboarding without the onboarding screen (Minor Revise UX RF-003).
 *
 * Every account gets an address the moment it first signs in, made from the
 * part of the email before the @ — `rani.p@gmail.com` becomes `ranip`, then
 * `ranip2`, `ranip3`… when taken or reserved. It is a starting point, not a
 * choice: Settings → Domain renames it, and that path keeps its rules.
 *
 * Same side effects as HandleController::claim, so an account made either
 * way ends up identical: a profile, the welcome Credits, `onboarded_at`.
 */
final class AutoHandle
{
    public static function give(User $user): void
    {
        if ($user->handle()->doesntExist()) {
            self::create($user);
        }

        $user->profile()->firstOrCreate([]);
        Credits::welcome($user);
        $user->forceFill(['onboarded_at' => $user->onboarded_at ?? now()])->save();
    }

    private static function create(User $user): void
    {
        $base = trim(Handle::normalize(strstr($user->email, '@', true) ?: $user->email), '-');

        if (strlen($base) < 3) {
            $base = 'creator';
        }

        // ponytail: linear probe; fine while a local-part has a few dozen twins.
        // Switch to a random suffix if signups ever make this loop long.
        for ($n = 1; $n < 1000; $n++) {
            $suffix = $n === 1 ? '' : (string) $n;
            $name = substr($base, 0, 30 - strlen($suffix)).$suffix;

            if (! Handle::isAvailable($name)) {
                continue;
            }

            try {
                // Released-and-past-grace handles are reassigned in place.
                $existing = Handle::query()->where('name', $name)->first();

                if ($existing !== null) {
                    $existing->forceFill(['user_id' => $user->id, 'released_at' => null])->save();
                } else {
                    Handle::query()->create(['name' => $name, 'user_id' => $user->id]);
                }

                return;
            } catch (UniqueConstraintViolationException) {
                // Somebody took it between the check and the insert — next one.
            }
        }
    }
}
