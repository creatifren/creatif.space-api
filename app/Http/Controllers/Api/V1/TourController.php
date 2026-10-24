<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TourController extends Controller
{
    /**
     * Mark the dashboard tour as seen (RF-004) — finished or skipped, the
     * same either way: it never opens on its own again. Keeps the first
     * date, so a replay from another tab does not move it.
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $user->forceFill(['tour_seen_at' => $user->tour_seen_at ?? now()])->save();

        return response()->noContent();
    }
}
