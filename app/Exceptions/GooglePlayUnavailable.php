<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Google Play could not be asked: no usable service account, no access
 * token, the API down or refusing us. Never a purchase token in the message.
 */
class GooglePlayUnavailable extends RuntimeException
{
    public function report(): void
    {
        Log::warning('google_play.unavailable', ['reason' => $this->getMessage()]);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'Google Play can’t be reached right now. Try again in a moment.',
            'reason' => 'google_unavailable',
        ], 503);
    }
}
