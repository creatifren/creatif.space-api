<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SocialPost;
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
    /**
     * v10.1 Post settings: `approval_default` — new posts need approval for
     * clients with an approver (Compose's default); `remind_approver` — the
     * Content Plan reminder email (SendContentPlanReminders), once, 24 hours
     * before the first waiting post. Both on unless turned off.
     */
    private const EMPTY = ['timetable' => [], 'labels' => [], 'templates' => [], 'views' => [], 'approval_default' => true, 'remind_approver' => true, 'saved_replies' => []];

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
            // { old name: new name } — the posts carrying a renamed label
            // follow it. A post stores the name, so without this it would
            // keep the old one, uncoloured.
            'approval_default' => ['sometimes', 'boolean'],
            'remind_approver' => ['sometimes', 'boolean'],
            // P6 Inbox: replies saved for one tap, above every reply box.
            'saved_replies' => ['sometimes', 'array', 'max:20'],
            'saved_replies.*' => ['string', 'min:1', 'max:500', 'distinct'],
            'renamed' => ['sometimes', 'array', 'max:50'],
            'renamed.*' => ['string', 'max:40'],
        ]);

        $owner = Workspace::owner($request->user());

        $renamed = array_filter($validated['renamed'] ?? [], fn ($to, $from) => $to !== $from, ARRAY_FILTER_USE_BOTH);
        unset($validated['renamed']);
        if ($renamed !== []) {
            SocialPost::query()->where('user_id', $owner->id)->whereNotNull('labels')->each(function (SocialPost $post) use ($renamed) {
                $labels = array_values(array_unique(array_map(fn ($l) => $renamed[$l] ?? $l, $post->labels)));
                if ($labels !== $post->labels) {
                    $post->update(['labels' => $labels]);
                }
            });
        }
        foreach (['approval_default', 'remind_approver'] as $flag) {
            if (array_key_exists($flag, $validated)) {
                $validated[$flag] = (bool) $validated[$flag];
            }
        }
        $settings = $validated + ($owner->social_settings ?? []) + self::EMPTY;
        $owner->forceFill(['social_settings' => $settings])->save();

        return response()->json(['data' => $settings]);
    }
}
