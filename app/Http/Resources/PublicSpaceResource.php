<?php

namespace App\Http\Resources;

use App\Enums\ApprovalStatus;
use App\Enums\NoteAuthor;
use App\Models\Approval;
use App\Models\Space;
use App\Models\SpaceItem;
use App\Support\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin Space
 */
class PublicSpaceResource extends JsonResource
{
    /**
     * This reader's decisions, resolved once per response.
     *
     * @var Collection<string, Approval>|null
     */
    private ?Collection $myApprovals = null;

    /**
     * The published page, sections built server-side from design.sections.
     * Mirrors the frontend's buildPublished(): hidden items and empty text
     * blocks never ship. `size` travels as the vocabulary token — geometry
     * (span/ar/minH) stays declared once, in the frontend tables.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $design = $this->design;
        $itemsByUlid = $this->items->keyBy('ulid');
        $itemMeta = (array) ($design['items'] ?? []);
        $texts = (array) ($design['texts'] ?? []);

        $sections = [];
        foreach ((array) ($design['sections'] ?? []) as $section) {
            $blocks = [];
            foreach ((array) ($section['blocks'] ?? []) as $block) {
                $built = $this->buildBlock($block, $itemsByUlid, $itemMeta, $texts, $request);
                if ($built !== null) {
                    $blocks[] = $built;
                }
            }

            $sections[] = [
                'key' => $section['key'] ?? 'section',
                'num' => $section['num'] ?? null,
                'title' => $section['title'] ?? null,
                'blocks' => $blocks,
            ];
        }

        return [
            // The beacon needs something to POST to, and the ULID is the
            // only id that ever leaves the server.
            'id' => $this->ulid,
            'title' => $this->title,
            'slug' => $this->slug,
            'owner' => [
                'name' => $this->user->name,
                'handle' => $this->user->handle?->name,
            ],
            /* The owner's palette, the same blob the profile page reads.
               A Space has no appearance of its own — one creator, one look,
               and a viewer that ignored it was the only public page that
               did. Empty object, not null: the client treats "no keys" as
               the default theme and would have to special-case null. */
            'appearance' => $this->user->profile?->appearance ?? (object) [],
            'approval' => $this->approvalBlock($request),
            'layout' => $this->view_mode,
            'fit' => $design['fit'] ?? 'cover',
            'labels' => $design['labels'] ?? ['name' => true, 'tags' => true],
            'allow_download' => (bool) ($this->settings['allow_download'] ?? true),
            // Same rule as the profile: paid plans drop the "Made with" line.
            'branding' => ! Workspace::owner($this->user)->plan()->feature('branding_removed'),
            'approval_enabled' => $this->approval_enabled,
            'seo' => $this->seo,
            'visibility' => $this->visibility,
            'published_at' => $this->published_at,
            'sections' => $sections,
        ];
    }

    /**
     * The approval state of this Space for whoever is reading it. Folded
     * into the page payload rather than a second call: the password and
     * expiry gates guard one request, and a second one would have to be
     * guarded all over again.
     *
     * `viewer` is null for a stranger — that is what makes the page show
     * "sign in to approve" instead of the buttons.
     *
     * @return array<string, mixed>
     */
    private function approvalBlock(Request $request): array
    {
        $client = $request->user('client');
        $mode = (string) ($this->settings['approval_mode'] ?? 'per_file');

        $block = [
            'enabled' => $this->approval_enabled,
            'mode' => $mode === 'per_space' ? 'per_space' : 'per_file',
            'viewer' => $client === null ? null : [
                'name' => $client->displayName(),
                'email' => $client->email,
                'avatar_url' => $client->avatar_url,
            ],
            'approved' => 0,
            'revision' => 0,
            'total' => 0,
        ];

        if (! $this->approval_enabled) {
            return $block;
        }

        $block['total'] = $this->items->count();

        if ($client === null) {
            return $block;
        }

        $mine = Approval::query()
            ->where('client_id', $client->id)
            ->whereIn('space_item_id', $this->items->pluck('id'))
            ->get();

        $block['approved'] = $mine->where('status', ApprovalStatus::Approved)->count();
        $block['revision'] = $mine->where('status', ApprovalStatus::Revision)->count();

        return $block;
    }

    /**
     * This client's decisions, keyed by item ulid, so each photo can carry
     * its own mark. Loaded once per response rather than per block.
     *
     * @return Collection<string, Approval>
     */
    private function myApprovals(Request $request): Collection
    {
        if ($this->myApprovals !== null) {
            return $this->myApprovals;
        }

        $client = $request->user('client');

        if ($client === null || ! $this->approval_enabled) {
            return $this->myApprovals = collect();
        }

        // id → ulid up front: the query below can only return approvals on
        // these items, so every row is guaranteed a key.
        /** @var array<int, string> $ulidByItemId */
        $ulidByItemId = $this->items->pluck('ulid', 'id')->all();

        return $this->myApprovals = Approval::query()
            ->where('client_id', $client->id)
            ->whereIn('space_item_id', array_keys($ulidByItemId))
            ->with('notes')
            ->get()
            ->keyBy(fn (Approval $a): string => $ulidByItemId[$a->space_item_id]);
    }

    /**
     * One design block → one public block, or null when it must not ship.
     *
     * @param  array<string, mixed>  $block
     * @param  Collection<string, SpaceItem>  $itemsByUlid
     * @param  array<string, mixed>  $itemMeta
     * @param  array<string, mixed>  $texts
     * @return array<string, mixed>|null
     */
    private function buildBlock(array $block, $itemsByUlid, array $itemMeta, array $texts, Request $request): ?array
    {
        if (($block['t'] ?? null) === 'text') {
            $text = $texts[$block['key'] ?? ''] ?? null;
            if ($text === null || trim((string) ($text['text'] ?? '')) === '') {
                return null; // an unwritten text block is not a thing to publish
            }

            return ['type' => 'text', 'text' => $text];
        }

        $item = $itemsByUlid[$block['id'] ?? ''] ?? null;
        if ($item === null) {
            return null;
        }

        $meta = (array) ($itemMeta[$item->ulid] ?? []);
        if (($meta['hidden'] ?? false) === true) {
            return null; // "Hide from the Space" is the promise it never ships
        }

        $file = $item->file;

        return ['type' => 'photo', 'photo' => [
            'id' => $item->ulid,
            'name' => $file->name,
            'src' => $file->url(),
            'alt' => $meta['alt'] ?? null,
            'size' => $meta['size'] ?? 'Medium',
            'size_bytes' => $file->size_bytes,
            'mime_type' => $file->mime_type,
            'is_video' => str_starts_with($file->mime_type, 'video/'),
            'plan_type' => $meta['plan_type'] ?? 'Feed',
            'caption' => $item->caption,
            'approval' => $this->photoApproval($item, $request),
        ]];
    }

    /**
     * The reading client's own mark on this photo — never anyone else's.
     * A client sees what they decided; the owner sees everything, but in
     * Insights, not here.
     *
     * @return array<string, mixed>|null
     */
    private function photoApproval(SpaceItem $item, Request $request): ?array
    {
        $approval = $this->myApprovals($request)->get($item->ulid);

        if ($approval === null || $approval->status === ApprovalStatus::Pending) {
            return null;
        }

        $note = $approval->notes->firstWhere('author_type', NoteAuthor::Client);

        return [
            'status' => $approval->status,
            'approved_at' => $approval->approved_at,
            'note' => $note === null ? null : [
                'body' => $note->body,
                'chips' => $note->chips ?? [],
            ],
        ];
    }
}
