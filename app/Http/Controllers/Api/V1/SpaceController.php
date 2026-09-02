<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SpaceStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SpaceListResource;
use App\Http\Resources\SpaceResource;
use App\Models\File;
use App\Models\Space;
use App\Models\SpaceItem;
use App\Support\PlanQuota;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SpaceController extends Controller
{
    /**
     * The Space list. Archived is its own drawer: it never appears under
     * the other tabs.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tab' => ['sometimes', 'string', 'in:all,private,public,approval,archived'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $tab = $validated['tab'] ?? 'all';
        $user = Workspace::owner($request->user());

        $query = $user->spaces()->with(['items.file'])->latest('updated_at');

        match ($tab) {
            'archived' => $query->where('status', SpaceStatus::Archived),
            'private' => $query->where('status', '!=', SpaceStatus::Archived)->where('visibility', 'private'),
            'public' => $query->where('status', '!=', SpaceStatus::Archived)->where('visibility', 'public'),
            'approval' => $query->where('status', '!=', SpaceStatus::Archived)->where('approval_enabled', true),
            default => $query->where('status', '!=', SpaceStatus::Archived),
        };

        if (($validated['search'] ?? null) !== null && $validated['search'] !== '') {
            $query->where('title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $validated['search']).'%');
        }

        return SpaceListResource::collection($query->get())
            ->additional(['meta' => ['quota' => PlanQuota::meta($user)]])
            ->response();
    }

    /**
     * New Space modal: name + purpose + gallery view. Always Draft+Private.
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless(Workspace::canWrite($request->user()), 403);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'purpose' => ['required', 'string', 'in:portfolio,approval'],
            'view_mode' => ['required', 'string', 'in:editorial,grid,board'],
        ]);

        $user = Workspace::owner($request->user());

        if (! PlanQuota::canCreate($user)) {
            throw ValidationException::withMessages([
                'title' => 'All '.PlanQuota::totalLimit($user).' Spaces are created.',
            ])->status(422);
        }

        $space = $user->spaces()->create([
            'title' => $validated['title'],
            'slug' => Space::generateSlug($user, $validated['title']),
            'view_mode' => $validated['view_mode'],
            'design' => Space::emptyDesign(),
            'settings' => Space::defaultSettings(),
        ]);

        $space->forceFill([
            'approval_enabled' => $validated['purpose'] === 'approval',
        ])->save();

        // refresh(): status/visibility come from column defaults.
        return (new SpaceResource($space->refresh()->load('items.file')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Editor bootstrap: the full document.
     */
    public function show(Request $request, Space $space): SpaceResource
    {
        abort_unless($space->user_id === Workspace::owner($request->user())->id, 404);

        return new SpaceResource($space->load('items.file'));
    }

    /**
     * The one save call. Columns + design/settings/seo + items reconciled
     * in a transaction.
     */
    public function update(Request $request, Space $space): SpaceResource
    {
        abort_unless($space->user_id === Workspace::owner($request->user())->id, 404);
        abort_unless(Workspace::canWrite($request->user()), 403);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'slug' => ['sometimes', 'string', 'min:1', 'max:80', 'regex:/^[a-z0-9-]+$/'],
            'visibility' => ['sometimes', 'string', 'in:private,public'],
            'view_mode' => ['sometimes', 'string', 'in:editorial,grid,board'],
            'approval_enabled' => ['sometimes', 'boolean'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'password' => ['sometimes', 'nullable', 'string', 'min:4', 'max:255'],
            'design' => ['sometimes', 'array'],
            'settings' => ['sometimes', 'array'],
            'seo' => ['sometimes', 'nullable', 'array'],
            'items' => ['sometimes', 'array', 'max:500'],
            'items.*.id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'items.*.file_id' => ['required_with:items', 'string', 'max:26'],
            'items.*.section' => ['sometimes', 'nullable', 'string', 'max:120'],
            'items.*.sort_order' => ['sometimes', 'integer', 'min:0'],
            'items.*.caption' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        if (strlen(json_encode($validated['design'] ?? []) ?: '') > 200_000) {
            throw ValidationException::withMessages(['design' => 'The design document is too large.']);
        }

        if (isset($validated['slug']) && $validated['slug'] !== $space->slug) {
            $taken = Space::withTrashed()
                ->where('user_id', $space->user_id)
                ->where('slug', $validated['slug'])
                ->where('id', '!=', $space->id)
                ->exists();
            if ($taken) {
                throw ValidationException::withMessages(['slug' => 'That address is already used by another Space.']);
            }
        }

        DB::transaction(function () use ($space, $validated, $request) {
            $space->fill(array_intersect_key($validated, array_flip([
                'title', 'description', 'slug', 'visibility', 'view_mode',
                'expires_at', 'design', 'settings', 'seo',
            ])));

            if (array_key_exists('approval_enabled', $validated)) {
                $space->approval_enabled = $validated['approval_enabled'];
            }
            if (array_key_exists('password', $validated)) {
                $space->password_hash = $validated['password'] === null
                    ? null
                    : Hash::make($validated['password']);
            }
            $space->save();

            if (array_key_exists('items', $validated)) {
                $this->syncItems($space, $validated['items'], $request->user()->id);
            }
        });

        return new SpaceResource($space->refresh()->load('items.file'));
    }

    /**
     * Publish — the active-slot gate.
     */
    public function publish(Request $request, Space $space): SpaceResource
    {
        abort_unless($space->user_id === Workspace::owner($request->user())->id, 404);
        abort_unless(Workspace::canWrite($request->user()), 403);

        if (! $space->isPublished()) {
            if (! PlanQuota::canPublish($request->user())) {
                throw ValidationException::withMessages([
                    'status' => 'All '.PlanQuota::activeLimit($request->user()).' active slots are in use.',
                ]);
            }

            $space->forceFill([
                'status' => SpaceStatus::Published,
                'published_at' => now(),
                'archived_at' => null,
            ])->save();
        }

        return new SpaceResource($space->load('items.file'));
    }

    public function unpublish(Request $request, Space $space): SpaceResource
    {
        abort_unless($space->user_id === Workspace::owner($request->user())->id, 404);
        abort_unless(Workspace::canWrite($request->user()), 403);

        $space->forceFill(['status' => SpaceStatus::Draft])->save();

        return new SpaceResource($space->load('items.file'));
    }

    public function archive(Request $request, Space $space): SpaceResource
    {
        abort_unless($space->user_id === Workspace::owner($request->user())->id, 404);
        abort_unless(Workspace::canWrite($request->user()), 403);

        $space->forceFill([
            'status' => SpaceStatus::Archived,
            'archived_at' => now(),
        ])->save();

        return new SpaceResource($space->load('items.file'));
    }

    /**
     * Reactivate: back to published when it was published before (active
     * gate applies), otherwise back to draft.
     */
    public function reactivate(Request $request, Space $space): SpaceResource
    {
        abort_unless($space->user_id === Workspace::owner($request->user())->id, 404);
        abort_unless(Workspace::canWrite($request->user()), 403);

        if ($space->published_at !== null) {
            if (! PlanQuota::canPublish($request->user())) {
                throw ValidationException::withMessages([
                    'status' => 'Active slots are full — archive another Space first.',
                ]);
            }
            $space->forceFill(['status' => SpaceStatus::Published, 'archived_at' => null])->save();
        } else {
            $space->forceFill(['status' => SpaceStatus::Draft, 'archived_at' => null])->save();
        }

        return new SpaceResource($space->load('items.file'));
    }

    /**
     * Duplicate — the total gate, refs remapped to fresh item ulids.
     */
    public function duplicate(Request $request, Space $space): JsonResponse
    {
        abort_unless($space->user_id === Workspace::owner($request->user())->id, 404);
        abort_unless(Workspace::canWrite($request->user()), 403);

        $user = Workspace::owner($request->user());

        if (! PlanQuota::canCreate($user)) {
            throw ValidationException::withMessages([
                'title' => 'All '.PlanQuota::totalLimit($user).' Spaces are created.',
            ]);
        }

        $copy = DB::transaction(function () use ($space, $user) {
            $title = $space->title.' (copy)';

            $copy = $user->spaces()->create([
                'title' => $title,
                'slug' => Space::generateSlug($user, $title),
                'description' => $space->description,
                'view_mode' => $space->view_mode->value,
                'design' => $space->design,
                'settings' => $space->settings,
                'seo' => $space->seo,
            ]);
            $copy->forceFill([
                'approval_enabled' => $space->approval_enabled,
            ])->save();

            // Copy items; remap old item ulid → new in design.items + blocks.
            $map = [];
            foreach ($space->items as $item) {
                $new = $copy->items()->create([
                    'file_id' => $item->file_id,
                    'section' => $item->section,
                    'sort_order' => $item->sort_order,
                    'caption' => $item->caption,
                ]);
                $map[$item->ulid] = $new->ulid;
            }

            $design = $copy->design;

            $remappedItems = [];
            foreach ((array) ($design['items'] ?? []) as $ulid => $meta) {
                $remappedItems[$map[$ulid] ?? $ulid] = $meta;
            }
            $design['items'] = $remappedItems ?: (object) [];

            $sections = [];
            foreach ((array) ($design['sections'] ?? []) as $section) {
                $blocks = [];
                foreach ((array) ($section['blocks'] ?? []) as $block) {
                    if (($block['t'] ?? null) === 'item' && isset($map[$block['id']])) {
                        $block['id'] = $map[$block['id']];
                    }
                    $blocks[] = $block;
                }
                $section['blocks'] = $blocks;
                $sections[] = $section;
            }
            $design['sections'] = $sections;

            $copy->forceFill(['design' => $design])->save();

            return $copy;
        });

        return (new SpaceResource($copy->refresh()->load('items.file')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Soft delete. The link dies; history stays.
     *
     * `revoke_access` used to be validated here and then never read: a client
     * could send `true`, get a 204 back, and reasonably conclude that Drive
     * sharing had been withdrawn. Nothing had happened. A security-relevant
     * flag that is silently dropped is worse than one that is refused, so it
     * is now refused — 422 with a sentence saying why.
     *
     * The reason it cannot be honoured is structural, not a missing feature:
     * with the `drive.file` scope Crefile never grants Drive sharing in the
     * first place (there is no permissions call anywhere in
     * GoogleDriveService), and public Spaces render cached thumbnail URLs
     * rather than the Drive file itself. There is nothing to revoke. If
     * per-file permission management ever lands, this becomes real work; it
     * should not pretend to be done until then.
     */
    public function destroy(Request $request, Space $space): JsonResponse
    {
        abort_unless($space->user_id === Workspace::owner($request->user())->id, 404);
        abort_unless(Workspace::canWrite($request->user()), 403);

        if ($request->boolean('revoke_access')) {
            throw ValidationException::withMessages([
                'revoke_access' => 'Creatif Space never changed this file’s sharing in Drive, so there is nothing here to revoke. Change it in Google Drive itself.',
            ]);
        }

        $space->delete();

        return response()->json(null, 204);
    }

    /**
     * Reconcile the items list: create, update, delete-absent — and check
     * every referenced file belongs to the caller.
     *
     * @param  list<array<string, mixed>>  $incoming
     */
    private function syncItems(Space $space, array $incoming, int $userId): void
    {
        $fileUlids = array_values(array_unique(array_column($incoming, 'file_id')));
        $files = File::query()
            ->whereIn('ulid', $fileUlids)
            ->where('user_id', $userId)
            ->where('status', File::STATUS_READY)
            ->get()
            ->keyBy('ulid');

        if ($files->count() !== count($fileUlids)) {
            throw ValidationException::withMessages([
                'items' => 'One or more files do not exist in your library.',
            ]);
        }

        $existing = $space->items()->get()->keyBy('ulid');
        $keep = [];

        foreach ($incoming as $row) {
            $file = $files[$row['file_id']];
            $attributes = [
                'file_id' => $file->id,
                'section' => $row['section'] ?? null,
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'caption' => $row['caption'] ?? null,
            ];

            /** @var SpaceItem|null $item */
            $item = isset($row['id']) ? $existing->get($row['id']) : null;
            $item ??= $space->items()->firstOrCreate(['file_id' => $file->id], $attributes);

            // firstOrCreate may hit an existing row for the same file; make
            // sure its position fields are current either way.
            $item->fill($attributes)->save();
            $keep[] = $item->id;
        }

        $space->items()->whereNotIn('id', $keep)->delete();
    }
}
