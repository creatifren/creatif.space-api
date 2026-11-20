<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\SampleData;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sample data (prototype v10): GET says whether there is any left (Today's
 * banner, Settings' row); DELETE removes all of it in one go ("Remove
 * sample data"). Session only — never offered to connected apps.
 */
class SampleDataController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => ['has_sample' => SampleData::has(Workspace::owner($request->user()))]]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $removed = SampleData::remove(Workspace::owner($request->user()));

        return response()->json(['data' => ['removed' => $removed, 'has_sample' => false]]);
    }
}
