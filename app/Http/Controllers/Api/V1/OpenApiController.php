<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\OpenApi;
use Illuminate\Http\JsonResponse;

/** GET /api/v1/openapi.json — the public v1 API as OpenAPI 3.1 (App\Support\OpenApi). */
class OpenApiController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(OpenApi::document(), 200, ['Cache-Control' => 'public, max-age=300'], JSON_UNESCAPED_SLASHES);
    }
}
