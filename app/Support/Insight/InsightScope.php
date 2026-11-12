<?php

namespace App\Support\Insight;

use App\Models\SocialAccount;
use App\Models\User;
use App\Support\PlanQuota;
use App\Support\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * What an Insight read is about: the workspace owner, its channels (all, or
 * the channel_ids asked for) and the period — ?month=YYYY-MM or
 * ?from=…&to=… (at most 92 days, not in the future). Shared by every
 * Insight read endpoint so they validate the same way.
 */
final class InsightScope
{
    /**
     * @param  Collection<int, SocialAccount>  $accounts
     */
    private function __construct(
        public readonly User $owner,
        public readonly Collection $accounts,
        public readonly InsightPeriod $period,
    ) {}

    /** Pro / Max only (402 plan_required on Free), then the period and channels. */
    public static function fromRequest(Request $request): self
    {
        $user = $request->user();
        PlanQuota::requireFeature($user, 'insight');

        $validated = $request->validate([
            'month' => ['required_without_all:from,to', 'nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'from' => ['required_without:month', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_without:month', 'nullable', 'date_format:Y-m-d'],
            'channel_ids' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $period = InsightPeriod::fromInput($validated, '');
        $owner = Workspace::owner($user);
        $accounts = InsightClients::accounts($owner);

        $asked = array_values(array_filter(array_map('trim', explode(',', (string) ($validated['channel_ids'] ?? '')))));
        if ($asked !== []) {
            $unknown = array_diff($asked, $accounts->pluck('ulid')->all());
            if ($unknown !== []) {
                throw ValidationException::withMessages(['channel_ids' => 'These channels are not connected to this workspace: '.implode(', ', $unknown).'.']);
            }
            $accounts = $accounts->filter(fn ($a) => in_array($a->ulid, $asked, true))->values();
        }

        if ($accounts->isEmpty()) {
            throw ValidationException::withMessages(['channel_ids' => 'Connect a channel first: there is nothing to report on.']);
        }

        return new self($owner, $accounts, $period);
    }
}
