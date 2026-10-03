<?php

namespace App\Http\Resources;

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
                $built = $this->buildBlock($block, $itemsByUlid, $itemMeta, $texts);
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
                /* Already public on the profile page, and the header here
                   reads "Winter Noel · Bandung" for the same reason it does
                   there. `user->profile` is eager-loaded for `appearance`
                   below, so this costs no query. */
                'location' => $this->user->profile?->location,
            ],
            /* The owner's palette, the same blob the profile page reads.
               A Space has no appearance of its own — one creator, one look,
               and a viewer that ignored it was the only public page that
               did. Empty object, not null: the client treats "no keys" as
               the default theme and would have to special-case null. */
            'appearance' => $this->user->profile?->appearance ?? (object) [],
            'layout' => $this->view_mode,
            'fit' => $design['fit'] ?? 'cover',
            'labels' => $design['labels'] ?? ['name' => true, 'tags' => true],
            'allow_download' => (bool) ($this->settings['allow_download'] ?? true),
            // Same rule as the profile: paid plans drop the "Made with" line.
            'branding' => ! Workspace::owner($this->user)->plan()->feature('branding_removed'),
            'seo' => $this->seo,
            'visibility' => $this->visibility,
            'published_at' => $this->published_at,
            /* "Updated 4 September". Distinct from published_at on purpose:
               a Space republished with three new photos is not a new Space,
               and the client checking it wants to know it changed. */
            'updated_at' => $this->updated_at,
            'sections' => $sections,
        ];
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
    private function buildBlock(array $block, $itemsByUlid, array $itemMeta, array $texts): ?array
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
        ]];
    }
}
