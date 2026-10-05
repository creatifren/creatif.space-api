<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The account's social settings: posting times (the Calendar's empty slots
 * and the drop ladder), labels, caption templates and saved views. One
 * document on the owner, shared by the team — there is no workspace entity.
 */
class SocialSettingsController extends Controller
{
    private const EMPTY = ['timetable' => [], 'labels' => [], 'templates' => [], 'views' => []];

    public function show(Request $request): JsonResponse
    {
        $owner = Workspace::owner($request->user());

        return response()->json(['data' => ($owner->social_settings ?? []) + self::EMPTY]);
    }

    /**
     * Replace whichever sections are sent; the rest are kept. Viewers
     * cannot write.
     */
    public function update(Request $request): JsonResponse
    {
        abort_unless(Workspace::canWrite($request->user()), 403, 'Viewers cannot change settings.');

        $validated = $request->validate([
            'timetable' => ['sometimes', 'array', 'max:70'],
            'timetable.*.day' => ['required', 'integer', 'between:0,6'],
            'timetable.*.time' => ['required', 'date_format:H:i'],
            'labels' => ['sometimes', 'array', 'max:50'],
            'labels.*.name' => ['required', 'string', 'max:40', 'distinct'],
            'labels.*.color' => ['required', 'string', 'in:forest,sea,clay,rust,ink'],
            'templates' => ['sometimes', 'array', 'max:50'],
            'templates.*.name' => ['required', 'string', 'max:60'],
            'templates.*.caption' => ['required', 'string', 'max:63206'],
            'views' => ['sometimes', 'array', 'max:30'],
            'views.*.name' => ['required', 'string', 'max:60'],
            'views.*.screen' => ['required', 'in:list,grid,feed,calendar'],
            'views.*.filter' => ['present', 'array'],
        ]);

        $owner = Workspace::owner($request->user());
        $settings = $validated + ($owner->social_settings ?? []) + self::EMPTY;
        $owner->forceFill(['social_settings' => $settings])->save();

        return response()->json(['data' => $settings]);
    }
}
