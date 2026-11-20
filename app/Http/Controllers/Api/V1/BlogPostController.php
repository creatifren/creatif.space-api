<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\File;
use App\Models\SocialApprovalLink;
use App\Models\Space;
use App\Support\PlanQuota;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Blog posts (prototype v7 › Blog post editor). A Blog is a Space of type
 * blog; a post is a cover, a title and blocks. "Needs client approval" puts
 * it on an approval link first: the client approves on /a/{token} and it
 * is published to the Blog then — never before (UX review H2).
 */
class BlogPostController extends Controller
{
    public function index(Request $request, Space $space): JsonResponse
    {
        $this->ownBlog($request, $space);
        $posts = $space->blogPosts()->latest('updated_at')->limit(200)->get();

        return response()->json(['data' => $posts->map(fn (BlogPost $p) => $this->postArray($p, false))->all()]);
    }

    /**
     * Every blog post across the owner's Blogs, for Today › "Blog posts · N"
     * and Content › Approvals › Sent (prototype v10.2). `status` filters
     * (comma list: draft, waiting, changes_requested, approved, published).
     * Each row carries its Blog, the approval link + approver, and the
     * number of client comments (the approval note). No blocks.
     */
    public function all(Request $request): JsonResponse
    {
        PlanQuota::requireFeature($request->user(), 'spaces');
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'max:80', 'regex:/^(draft|waiting|changes_requested|approved|published)(,(draft|waiting|changes_requested|approved|published))*$/'],
        ]);
        $owner = Workspace::owner($request->user());
        $posts = BlogPost::query()
            ->where('user_id', $owner->id)
            ->when(isset($validated['status']), fn ($q) => $q->whereIn('status', explode(',', $validated['status'])))
            ->with(['space:id,ulid,title,slug', 'approvalLink.clientLabel'])
            ->latest('updated_at')
            ->limit(100)
            ->get();

        return response()->json(['data' => $posts->map(fn (BlogPost $p) => [
            ...$this->postArray($p, false),
            'sample' => (bool) $p->is_demo,
            'space' => $p->space === null ? null : ['id' => $p->space->ulid, 'title' => $p->space->title, 'slug' => $p->space->slug],
            'approval_link' => $p->approvalLink === null ? null : [
                'id' => $p->approvalLink->ulid,
                'url' => $p->approvalLink->url(),
                'revoked' => $p->approvalLink->revoked_at !== null,
                'client' => $p->approvalLink->clientLabel->name ?? $p->approvalLink->client_name,
                'approver' => [
                    'name' => $p->approvalLink->clientLabel?->approver_name,
                    'email' => $p->approvalLink->approver_email,
                ],
            ],
            'comments_count' => ($p->approval_comment ?? '') !== '' ? 1 : 0,
        ])->all()]);
    }

    public function store(Request $request, Space $space): JsonResponse
    {
        $this->ownBlog($request, $space);
        abort_unless(Workspace::canWrite($request->user()), 403);
        $validated = $request->validate($this->rules(false));

        $post = $space->blogPosts()->create([
            'user_id' => $space->user_id,
            'title' => trim($validated['title']),
            'slug' => isset($validated['slug']) ? $this->freeSlug($space, $validated['slug']) : BlogPost::slugFor($space, $validated['title']),
            'cover_file_id' => $this->fileId($space, $validated['cover_file_id'] ?? null),
            'blocks' => $this->blocks($space, $validated['blocks'] ?? []),
            'needs_approval' => (bool) ($validated['needs_approval'] ?? false),
        ]);

        return response()->json(['data' => $this->postArray($post)], 201);
    }

    public function show(Request $request, BlogPost $blogPost): JsonResponse
    {
        $this->ownBlog($request, $blogPost->space);

        return response()->json(['data' => $this->postArray($blogPost)]);
    }

    public function update(Request $request, BlogPost $blogPost): JsonResponse
    {
        $space = $blogPost->space;
        $this->ownBlog($request, $space);
        abort_unless(Workspace::canWrite($request->user()), 403);
        $validated = $request->validate($this->rules(true));

        $fields = [];
        if (isset($validated['title'])) {
            $fields['title'] = trim($validated['title']);
        }
        if (isset($validated['slug']) && $validated['slug'] !== $blogPost->slug) {
            $fields['slug'] = $this->freeSlug($space, $validated['slug'], $blogPost->id);
        }
        if (array_key_exists('cover_file_id', $validated)) {
            $fields['cover_file_id'] = $this->fileId($space, $validated['cover_file_id']);
        }
        if (array_key_exists('blocks', $validated)) {
            $fields['blocks'] = $this->blocks($space, $validated['blocks'] ?? []);
        }
        if (array_key_exists('needs_approval', $validated)) {
            $fields['needs_approval'] = (bool) $validated['needs_approval'];
        }
        // An edit after the client asked for changes goes back to draft.
        if ($blogPost->status === 'changes_requested' && ($fields !== [])) {
            $fields['status'] = 'draft';
        }
        $blogPost->update($fields);

        return response()->json(['data' => $this->postArray($blogPost->fresh())]);
    }

    public function destroy(Request $request, BlogPost $blogPost): JsonResponse
    {
        $this->ownBlog($request, $blogPost->space);
        abort_unless(Workspace::canWrite($request->user()), 403);
        $blogPost->delete();

        return response()->json(null, 204);
    }

    /** Publish now: only when it needs no approval, or the client approved it. */
    public function publish(Request $request, BlogPost $blogPost): JsonResponse
    {
        $this->ownBlog($request, $blogPost->space);
        abort_unless(Workspace::canWrite($request->user()), 403);
        if ($blogPost->needs_approval && ! in_array($blogPost->status, ['approved', 'published'], true)) {
            return response()->json(['message' => 'This post needs the client’s approval first.', 'reason' => 'needs_approval'], 422);
        }
        $blogPost->update(['status' => 'published', 'published_at' => $blogPost->published_at ?? now()]);

        return response()->json(['data' => $this->postArray($blogPost->fresh())]);
    }

    /** Back to draft: off the public Blog. */
    public function unpublish(Request $request, BlogPost $blogPost): JsonResponse
    {
        $this->ownBlog($request, $blogPost->space);
        abort_unless(Workspace::canWrite($request->user()), 403);
        $blogPost->update(['status' => 'draft', 'published_at' => null]);

        return response()->json(['data' => $this->postArray($blogPost->fresh())]);
    }

    /**
     * Send for approval: onto an existing live link of the owner's
     * (link_id), or a new link for a client (client_id) or an approver
     * (approver_email). The post is published when the client approves.
     */
    public function sendForApproval(Request $request, BlogPost $blogPost): JsonResponse
    {
        $space = $blogPost->space;
        $this->ownBlog($request, $space);
        abort_unless(Workspace::canWrite($request->user()), 403);
        PlanQuota::requireClientApprovals($request->user(), 'approver_email');
        $validated = $request->validate([
            'link_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'client_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'approver_email' => ['sometimes', 'nullable', 'email:rfc', 'max:254'],
        ]);

        $owner = $space->user;
        if (! empty($validated['link_id'])) {
            $link = SocialApprovalLink::query()->where('user_id', $owner->id)->where('ulid', $validated['link_id'])->whereNull('revoked_at')->first();
            abort_if($link === null, 404);
        } else {
            $label = empty($validated['client_id']) ? null : $owner->clientLabels()->where('ulid', $validated['client_id'])->first();
            if (! empty($validated['client_id']) && $label === null) {
                throw ValidationException::withMessages(['client_id' => 'That client is not one of yours.']);
            }
            $email = $validated['approver_email'] ?? $label?->approver_email;
            if ($email === null) {
                throw ValidationException::withMessages(['approver_email' => 'Add the email address of the person who approves.']);
            }
            $link = SocialApprovalLink::create([
                'user_id' => $owner->id,
                'title' => $blogPost->title,
                'client_name' => $label?->name,
                'client_label_id' => $label?->id,
                'approver_email' => mb_strtolower($email),
                'visibility' => 'link',
            ]);
        }

        $blogPost->update(['needs_approval' => true, 'status' => 'waiting', 'social_approval_link_id' => $link->id, 'approval_by' => null, 'approval_at' => null, 'approval_comment' => null]);

        return response()->json(['data' => $this->postArray($blogPost->fresh()) + ['approval_link' => ['id' => $link->ulid, 'url' => $link->url()]]]);
    }

    /** @return array<string, list<mixed>> */
    private function rules(bool $update): array
    {
        return [
            'title' => [$update ? 'sometimes' : 'required', 'string', 'min:1', 'max:200'],
            'slug' => ['sometimes', 'string', 'min:1', 'max:160', 'regex:/^[a-z0-9-]+$/'],
            'cover_file_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'blocks' => ['sometimes', 'nullable', 'array', 'max:200'],
            'blocks.*.type' => ['required', 'string', Rule::in(BlogPost::BLOCKS)],
            'blocks.*.text' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'blocks.*.caption' => ['sometimes', 'nullable', 'string', 'max:500'],
            'blocks.*.file_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'blocks.*.items' => ['sometimes', 'array', 'max:100'],
            'blocks.*.items.*' => ['string', 'max:2000'],
            'needs_approval' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function blocks(Space $space, array $blocks): array
    {
        $out = [];
        foreach ($blocks as $b) {
            $type = $b['type'];
            $block = ['type' => $type];
            if (in_array($type, ['paragraph', 'heading', 'quote'], true)) {
                $block['text'] = (string) ($b['text'] ?? '');
            }
            if ($type === 'list') {
                $block['items'] = array_values(array_map('strval', (array) ($b['items'] ?? [])));
            }
            if ($type === 'photo') {
                $id = $b['file_id'] ?? null;
                if ($id !== null && $this->fileId($space, $id) === null) {
                    throw ValidationException::withMessages(['blocks' => 'A photo is not a ready file in your library.']);
                }
                $block['file_id'] = $id;
                $block['caption'] = $b['caption'] ?? null;
            }
            $out[] = $block;
        }

        return $out;
    }

    private function fileId(Space $space, ?string $ulid): ?int
    {
        if ($ulid === null || $ulid === '') {
            return null;
        }
        $id = File::query()->where('ulid', $ulid)->where('user_id', $space->user_id)->where('status', File::STATUS_READY)->value('id');
        if ($id === null) {
            throw ValidationException::withMessages(['cover_file_id' => 'That file is not a ready file in your library.']);
        }

        return (int) $id;
    }

    private function freeSlug(Space $space, string $slug, ?int $except = null): string
    {
        $taken = BlogPost::query()->where('space_id', $space->id)->where('slug', $slug)->when($except, fn ($q) => $q->whereKeyNot($except))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['slug' => 'Another post in this Blog has that address.']);
        }

        return $slug;
    }

    private function ownBlog(Request $request, ?Space $space): void
    {
        abort_if($space === null || $space->user_id !== Workspace::owner($request->user())->id, 404);
        abort_unless(($space->type ?? 'gallery') === 'blog', 422, 'This Space is a Gallery, not a Blog.');
        PlanQuota::requireFeature($request->user(), 'spaces');
    }

    /** @return array<string, mixed> */
    public static function postArray(BlogPost $post, bool $withBlocks = true): array
    {
        $cover = $post->cover_file_id === null ? null : File::query()->find($post->cover_file_id);

        return [
            'id' => $post->ulid,
            'space_id' => $post->space()->value('ulid'),
            'title' => $post->title,
            'slug' => $post->slug,
            'cover' => $cover === null ? null : ['file_id' => $cover->ulid, 'url' => $cover->url()],
            'status' => $post->status,
            'needs_approval' => $post->needs_approval,
            'approval_by' => $post->approval_by,
            'approval_at' => $post->approval_at?->toIso8601String(),
            'approval_comment' => $post->approval_comment,
            'published_at' => $post->published_at?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),
            ...($withBlocks ? ['blocks' => $post->publicBlocks()] : []),
        ];
    }
}
