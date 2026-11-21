<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SocialPostApproval;
use App\Http\Controllers\Controller;
use App\Models\ClientLabel;
use App\Models\SocialAccount;
use App\Support\ClientBadges;
use App\Support\PlanQuota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Client labels (prototype v7 · Preferences › Clients, Channels). The owner
 * makes clients and puts them on social accounts; one account can carry
 * several. A post belongs to every label on its accounts, so nothing else
 * stores a client: filters, approval links and reports all read these.
 */
class ClientLabelController extends Controller
{
    private const MAX = 200;

    public function index(Request $request): JsonResponse
    {
        $labels = $request->user()->clientLabels()
            ->with('accounts:id,ulid,platform,username')
            ->orderBy('name')
            ->limit(self::MAX)
            ->get();

        return response()->json(['data' => $labels->map(fn (ClientLabel $l) => $this->labelArray($request, $l))->all()]);
    }

    public function show(Request $request, ClientLabel $clientLabel): JsonResponse
    {
        Gate::allowIf(fn ($user) => $clientLabel->user_id === $user->id);

        return response()->json(['data' => $this->labelArray($request, $clientLabel->load('accounts:id,ulid,platform,username'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate($this->rules($request, null));

        if ($user->clientLabels()->count() >= self::MAX) {
            return response()->json(['message' => 'You have '.self::MAX.' clients — remove one to add another.'], 422);
        }
        // Free: one client (backlog #2, Rizal 20 Nov). Sample labels don't count.
        PlanQuota::requireClientLabelRoom($user);

        $label = $user->clientLabels()->create([
            'name' => trim($validated['name']),
            'initials' => isset($validated['initials']) ? mb_strtoupper($validated['initials']) : null,
            'color' => $validated['color'] ?? ClientLabel::COLORS[$user->clientLabels()->count() % count(ClientLabel::COLORS)],
            'approver_email' => isset($validated['approver_email']) ? mb_strtolower(trim($validated['approver_email'])) : null,
            'approver_name' => $validated['approver_name'] ?? null,
            'report_email' => $validated['report_email'] ?? true,
        ]);

        if (array_key_exists('account_ids', $validated)) {
            $label->accounts()->sync($this->ownAccountIds($request, $validated['account_ids'] ?? []));
        }

        ClientBadges::flush();
        return response()->json(['data' => $this->labelArray($request, $label->load('accounts:id,ulid,platform,username'))], 201);
    }

    public function update(Request $request, ClientLabel $clientLabel): JsonResponse
    {
        Gate::allowIf(fn ($user) => $clientLabel->user_id === $user->id);
        $validated = $request->validate($this->rules($request, $clientLabel));

        $fields = [];
        foreach (['name', 'initials', 'color', 'approver_email', 'approver_name', 'report_email'] as $key) {
            if (array_key_exists($key, $validated)) {
                $fields[$key] = match ($key) {
                    'name' => trim((string) $validated[$key]),
                    'initials' => $validated[$key] === null ? ClientLabel::initialsOf($validated['name'] ?? $clientLabel->name) : mb_strtoupper($validated[$key]),
                    'approver_email' => $validated[$key] === null ? null : mb_strtolower(trim($validated[$key])),
                    default => $validated[$key],
                };
            }
        }
        if (array_key_exists('reply_style', $validated)) {
            $fields['reply_style'] = \App\Support\ReplyStyle::clean($validated['reply_style']);
        }
        $clientLabel->update($fields);

        ClientBadges::flush();
        if (array_key_exists('account_ids', $validated)) {
            $clientLabel->accounts()->sync($this->ownAccountIds($request, $validated['account_ids'] ?? []));
        }

        return response()->json(['data' => $this->labelArray($request, $clientLabel->load('accounts:id,ulid,platform,username'))]);
    }

    /**
     * Removing a client removes the label only: its accounts, posts and
     * approval links stay (links keep their client_name).
     */
    public function destroy(ClientLabel $clientLabel): JsonResponse
    {
        Gate::allowIf(fn ($user) => $clientLabel->user_id === $user->id);
        ClientBadges::flush();
        $clientLabel->delete();

        return response()->json(null, 204);
    }

    /**
     * Channels › Labels: the full set of clients on one account (replaces).
     */
    public function setForAccount(Request $request, SocialAccount $socialAccount): JsonResponse
    {
        Gate::allowIf(fn ($user) => $socialAccount->user_id === $user->id);
        $validated = $request->validate([
            'client_ids' => ['present', 'array', 'max:'.self::MAX],
            'client_ids.*' => ['string', 'max:26'],
        ]);

        $ids = $request->user()->clientLabels()->whereIn('ulid', $validated['client_ids'])->pluck('id')->all();
        if (count($ids) !== count(array_unique($validated['client_ids']))) {
            return response()->json(['message' => 'One of these clients is not yours.'], 422);
        }
        ClientBadges::flush();
        $socialAccount->clientLabels()->sync($ids);

        return response()->json(['data' => [
            'account_id' => $socialAccount->ulid,
            'clients' => $socialAccount->clientLabels()->orderBy('name')->get()->map(fn (ClientLabel $l) => $l->toBadge())->all(),
        ]]);
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, ?ClientLabel $label): array
    {
        $unique = Rule::unique('client_labels', 'name')->where('user_id', $request->user()->id);
        if ($label !== null) {
            $unique->ignore($label->id);
        }

        return [
            'name' => [$label ? 'sometimes' : 'required', 'string', 'min:1', 'max:80', $unique],
            'initials' => ['sometimes', 'nullable', 'string', 'min:1', 'max:3'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'approver_email' => ['sometimes', 'nullable', 'email:rfc', 'max:254'],
            'approver_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'report_email' => ['sometimes', 'boolean'],
            'account_ids' => ['sometimes', 'nullable', 'array', 'max:500'],
            'account_ids.*' => ['string', 'max:26'],
            // v10.6: "Own style for this client" (null = the workspace default).
            ...\App\Support\ReplyStyle::rules('reply_style'),
        ];
    }

    /**
     * The caller's own account ids for these ulids; an unknown one is dropped.
     *
     * @param  array<int, string>  $ulids
     * @return array<int, int>
     */
    private function ownAccountIds(Request $request, array $ulids): array
    {
        return $request->user()->socialAccounts()->whereIn('ulid', $ulids)->pluck('id')->all();
    }

    /** @return array<string, mixed> */
    private function labelArray(Request $request, ClientLabel $label): array
    {
        $accountIds = $label->accounts->pluck('id')->all();
        // Shared accounts: another of the owner's labels is on it too.
        $shared = $accountIds === [] ? [] : DB::table('client_label_social_account')
            ->whereIn('social_account_id', $accountIds)
            ->where('client_label_id', '!=', $label->id)
            ->pluck('social_account_id')->unique()->all();

        $posts = $accountIds === [] ? 0 : $request->user()->socialPosts()
            ->whereHas('targets', fn ($q) => $q->whereIn('social_account_id', $accountIds))
            ->count();
        $waiting = $accountIds === [] ? 0 : $request->user()->socialPosts()
            ->where('approval', SocialPostApproval::Client->value)
            ->whereHas('targets', fn ($q) => $q->whereIn('social_account_id', $accountIds))
            ->count();

        return [
            ...$label->toBadge(),
            'approver_email' => $label->approver_email,
            'approver_name' => $label->approver_name,
            'report_email' => $label->report_email,
            'reply_style' => $label->reply_style,
            'sample' => (bool) $label->is_demo,
            'accounts' => $label->accounts->map(fn (SocialAccount $a) => [
                'id' => $a->ulid,
                'platform' => $a->platform,
                'username' => $a->username,
                'shared' => in_array($a->id, $shared, true),
            ])->values()->all(),
            'posts' => $posts,
            'waiting' => $waiting,
            'created_at' => $label->created_at?->toIso8601String(),
        ];
    }
}
