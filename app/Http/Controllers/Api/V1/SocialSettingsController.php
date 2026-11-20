<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SocialPost;
use App\Models\User;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
     *
     * v10.2 (Rizal 20 Nov, option A): `hashtag_sets` — saved hashtags per
     * client ({id, name, tags, client_id}), Compose's Hashtags button;
     * `first_comment` — Compose shows a First comment field (off by default).
     */
    private const EMPTY = ['timetable' => [], 'labels' => [], 'templates' => [], 'views' => [], 'approval_default' => true, 'remind_approver' => true, 'saved_replies' => [], 'hashtag_sets' => [], 'first_comment' => false];

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
            // v10.2: hashtag sets per client + the First comment switch.
            'hashtag_sets' => ['sometimes', 'array', 'max:100'],
            'hashtag_sets.*.id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'hashtag_sets.*.name' => ['required', 'string', 'min:1', 'max:40'],
            'hashtag_sets.*.tags' => ['required', 'array', 'min:1', 'max:30'],
            'hashtag_sets.*.tags.*' => ['string', 'min:1', 'max:100'],
            'hashtag_sets.*.client_id' => ['present', 'nullable', 'string', 'max:26'],
            'first_comment' => ['sometimes', 'boolean'],
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
        if (array_key_exists('hashtag_sets', $validated)) {
            $validated['hashtag_sets'] = self::hashtagSets($owner, $validated['hashtag_sets']);
        }
        foreach (['approval_default', 'remind_approver', 'first_comment'] as $flag) {
            if (array_key_exists($flag, $validated)) {
                $validated[$flag] = (bool) $validated[$flag];
            }
        }
        $settings = $validated + ($owner->social_settings ?? []) + self::EMPTY;
        $owner->forceFill(['social_settings' => $settings])->save();

        return response()->json(['data' => $settings]);
    }

    /**
     * Hashtag sets as stored: an id each (kept, or made), tags with one
     * leading # and no spaces, duplicates dropped, and a client that is one
     * of the owner's labels (else none).
     *
     * @param  list<array{id?: string|null, name: string, tags: list<string>, client_id: string|null}>  $sets
     * @return list<array{id: string, name: string, tags: list<string>, client_id: string|null}>
     */
    private static function hashtagSets(User $owner, array $sets): array
    {
        $clients = $owner->clientLabels()->pluck('ulid')->all();

        return array_values(array_map(function (array $set) use ($clients): array {
            $tags = [];
            foreach ($set['tags'] as $tag) {
                foreach (preg_split('/[\s,]+/', $tag) ?: [] as $one) {
                    $one = '#'.ltrim(trim($one), '#');
                    if ($one !== '#' && ! in_array($one, $tags, true)) {
                        $tags[] = $one;
                    }
                }
            }

            return [
                'id' => (string) ($set['id'] ?? '') !== '' ? (string) $set['id'] : 'hs_'.Str::lower(Str::random(10)),
                'name' => trim($set['name']),
                'tags' => array_slice($tags, 0, 30),
                'client_id' => in_array($set['client_id'] ?? null, $clients, true) ? $set['client_id'] : null,
            ];
        }, $sets));
    }
}
