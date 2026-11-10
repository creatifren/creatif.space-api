<?php

namespace App\Http\Controllers\Api\V1;

use App\Casts\UtcDateTime;
use App\Enums\SocialPostStatus;
use App\Http\Controllers\Controller;
use App\Models\SocialPlanLink;
use App\Models\SocialPost;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Content Plan share links. The owner makes, lists and revokes them; anyone
 * holding the token reads the plan — no actions, no comments.
 *
 * Ladder, same as /a/{token}: 404 unknown, 409 revoked.
 */
class SocialPlanLinkController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'account_ids' => ['sometimes', 'nullable', 'array', 'max:100'],
            'account_ids.*' => ['string'],
            'sections' => ['sometimes', 'nullable', 'array', 'min:1'],
            'sections.*' => ['string', Rule::in(SocialPlanLink::SECTIONS)],
        ]);

        // Only the owner's own accounts; an unknown id is dropped, not trusted.
        $accounts = empty($validated['account_ids'])
            ? null
            : $request->user()->socialAccounts()->whereIn('ulid', $validated['account_ids'])->pluck('id')->all();

        if ($accounts === []) {
            return response()->json(['message' => 'None of these accounts are yours.'], 422);
        }

        $link = SocialPlanLink::create([
            'user_id' => $request->user()->id,
            'title' => $validated['title'] ?? null,
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
            'account_ids' => $accounts,
            'sections' => isset($validated['sections']) ? array_values(array_unique($validated['sections'])) : null,
        ]);

        return response()->json(['data' => $this->linkArray($link)], 201);
    }

    /**
     * ponytail: capped, no paging. Page it once accounts hold ~100 links.
     */
    public function index(Request $request): JsonResponse
    {
        $links = SocialPlanLink::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('revoked_at')
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json(['data' => $links->map(fn ($l) => $this->linkArray($l))->all()]);
    }

    public function destroy(SocialPlanLink $socialPlanLink): JsonResponse
    {
        Gate::allowIf(fn ($user) => $socialPlanLink->user_id === $user->id);

        $socialPlanLink->update(['revoked_at' => $socialPlanLink->revoked_at ?? now()]);

        return response()->json(['data' => $this->linkArray($socialPlanLink)]);
    }

    public function show(string $token): JsonResponse
    {
        $link = SocialPlanLink::query()->where('token_hash', SocialPlanLink::hashToken($token))->with('user')->first();
        if ($link === null) {
            abort(404);
        }
        if ($link->revoked_at !== null) {
            return response()->json(['message' => 'This link was taken back.', 'editor' => $link->user->name], 409);
        }

        $ids = $link->account_ids;
        $posts = $link->user->socialPosts()
            ->where('status', '!=', SocialPostStatus::Cancelled->value)
            // A send-now post has no schedule; it sits on the day it was made.
            // scheduled_at holds UTC, created_at app-timezone wall time.
            ->where(fn ($q) => $q
                ->whereBetween('scheduled_at', [UtcDateTime::bind($link->starts_at), UtcDateTime::bind($link->ends_at)])
                ->orWhere(fn ($u) => $u->whereNull('scheduled_at')->whereBetween('created_at', [$link->starts_at, $link->ends_at])))
            ->where(fn ($q) => $q->whereNotNull('scheduled_at')->orWhere('status', '!=', SocialPostStatus::Draft->value))
            ->when($ids !== null, fn ($q) => $q->whereHas('targets', fn ($t) => $t->whereIn('social_account_id', $ids)))
            ->with('targets.account')
            ->limit(500)
            ->get()
            // In time order across both columns' zones (instants, not strings).
            ->sortBy(fn (SocialPost $p) => ($p->scheduled_at ?? $p->created_at)->getTimestamp())
            ->values();

        $accounts = $link->user->socialAccounts()
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->get(['platform', 'username']);

        return response()->json(['data' => [
            'editor' => $link->user->name,
            'title' => $link->title,
            'starts_at' => $link->starts_at->toIso8601String(),
            'ends_at' => $link->ends_at->toIso8601String(),
            'sections' => $link->sections ?? SocialPlanLink::SECTIONS,
            // The owner's plan, as public Spaces read it: the page hides its
            // footer slogan when branding is removed.
            'branding_removed' => Workspace::owner($link->user)->plan()->feature('branding_removed'),
            'accounts' => $accounts->map(fn ($a) => ['platform' => $a->platform, 'username' => $a->username])->all(),
            'posts' => $posts->map(fn (SocialPost $p) => [
                'id' => $p->ulid,
                'caption' => $p->caption,
                'type' => $p->type,
                'media' => $p->media ?? [],
                'at' => ($p->scheduled_at ?? $p->created_at)->toIso8601String(),
                'status' => $p->status->value,
                // Only the accounts on this plan, never the provider fields.
                'targets' => $p->targets
                    ->filter(fn ($t) => $ids === null || in_array((int) $t->social_account_id, $ids, true))
                    ->map(fn ($t) => ['platform' => $t->account->platform, 'username' => $t->account->username])
                    ->values()->all(),
            ])->all(),
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function linkArray(SocialPlanLink $link): array
    {
        return [
            'id' => $link->ulid,
            'url' => $link->url(),
            'title' => $link->title,
            'starts_at' => $link->starts_at->toIso8601String(),
            'ends_at' => $link->ends_at->toIso8601String(),
            'sections' => $link->sections ?? SocialPlanLink::SECTIONS,
            'revoked_at' => $link->revoked_at?->toIso8601String(),
            'created_at' => $link->created_at?->toIso8601String(),
        ];
    }
}
