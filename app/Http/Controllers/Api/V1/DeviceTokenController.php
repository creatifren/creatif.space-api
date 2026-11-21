<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/devices {token, app_version?} — the Android app registers
 * the phone for push (upsert: a token moves to whoever signed in last).
 * DELETE /api/v1/devices/{token} — on sign-out, so the next person on the
 * phone never gets this account's pushes (another person's token: 404).
 */
class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:512'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);
        $device = DeviceToken::query()->firstOrNew(['token' => $data['token']]);
        $device->user_id = $request->user()->id;
        $device->platform = 'android';
        $device->app_version = $data['app_version'] ?? $device->app_version;
        $device->last_seen_at = now();
        $device->save();

        return response()->json(['data' => ['registered' => true, 'push_enabled' => $request->user()->pushEnabled()]], $device->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, string $token): JsonResponse
    {
        $gone = DeviceToken::query()->where('user_id', $request->user()->id)->where('token', $token)->delete();

        return $gone > 0 ? response()->json(null, 204) : response()->json(['message' => 'Not found.'], 404);
    }
}
