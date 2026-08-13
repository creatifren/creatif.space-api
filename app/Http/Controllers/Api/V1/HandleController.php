<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Handle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HandleController extends Controller
{
    /**
     * Live availability check while the user types (onboarding step 1 and
     * the landing-page address field). Public: no auth required.
     */
    public function availability(Request $request): JsonResponse
    {
        $name = Handle::normalize((string) $request->query('name', ''));

        if (strlen($name) < 3) {
            return response()->json([
                'name' => $name,
                'available' => false,
                'reason' => 'too_short',
            ]);
        }

        $available = Handle::isAvailable($name);

        return response()->json([
            'name' => $name,
            'available' => $available,
            'reason' => $available ? null : 'taken',
            'suggestions' => $available ? [] : $this->suggestions($name),
        ]);
    }

    /**
     * Claim a handle for the authenticated user. One handle per user, set
     * once — changing it later is deliberately unsupported (sent links must
     * keep working).
     */
    public function claim(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[a-z0-9-]+$/'],
        ]);

        $user = $request->user();

        if ($user->handle !== null) {
            throw ValidationException::withMessages([
                'name' => 'You already have an address. It cannot be changed.',
            ]);
        }

        $name = Handle::normalize($validated['name']);

        // Serialize concurrent claims on the same name: the unique index is
        // the real guard; the transaction turns a race into a clean 422.
        try {
            $handle = DB::transaction(function () use ($name, $user) {
                if (! Handle::isAvailable($name)) {
                    throw ValidationException::withMessages([
                        'name' => "creatif.space/{$name} is already taken.",
                    ]);
                }

                // Released-and-past-grace handles are reassigned in place.
                $existing = Handle::query()->where('name', $name)->first();

                if ($existing !== null) {
                    $existing->forceFill([
                        'user_id' => $user->id,
                        'released_at' => null,
                    ])->save();

                    return $existing;
                }

                return Handle::query()->create([
                    'name' => $name,
                    'user_id' => $user->id,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => "creatif.space/{$name} is already taken.",
            ]);
        }

        // The empty profile is created together with the handle so the
        // public page exists the moment the address does. Claiming also
        // completes onboarding — the Drive step is skippable by design.
        $user->profile()->firstOrCreate([]);
        $user->forceFill(['onboarded_at' => $user->onboarded_at ?? now()])->save();

        return response()->json([
            'data' => ['name' => $handle->name],
        ], 201);
    }

    /**
     * Mirror the frontend's suggestion style: name-studio, namefoto, name01.
     *
     * @return list<string>
     */
    private function suggestions(string $name): array
    {
        $candidates = ["{$name}-studio", "{$name}foto", "{$name}01"];

        return array_values(array_filter(
            $candidates,
            fn (string $candidate): bool => strlen($candidate) <= 30 && Handle::isAvailable($candidate),
        ));
    }
}
