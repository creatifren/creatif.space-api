<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Space;
use App\Models\User;
use App\Support\Analytics;
use App\Support\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;

/**
 * Insights → Analytics, in one call.
 *
 * Free gets the headline: how many views, on which days. Everything that
 * explains those views — who, from where, at what hour — is what a paid
 * plan buys, and it ships as `null` rather than as a missing key, so the
 * frontend can keep its blur-and-upgrade layout without guessing the plan.
 *
 * `null` means "not on your plan". `[]` means "yours, and empty". The
 * difference matters: an empty chart is not a locked one.
 */
class AnalyticsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'range' => ['sometimes', 'string', 'in:this_month,last_month,this_year,custom'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'space' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);

        // A team member reads the owner's traffic, on the owner's plan —
        // the numbers belong to the account, not to whoever is looking.
        $user = Workspace::owner($request->user());
        $full = $user->plan()->feature('full_analytics');
        $rangeKey = $validated['range'] ?? 'this_month';

        if ($rangeKey === 'custom' && ! $full) {
            throw ValidationException::withMessages([
                'range' => 'Picking your own dates is part of Premium.',
            ]);
        }

        [$from, $to, $monthly] = $this->window($rangeKey, $validated);

        $spaceIds = Analytics::spaceIds($user, $validated['space'] ?? null);

        $series = Analytics::series($spaceIds, $from, $to, $monthly);
        $totals = Analytics::totals($spaceIds, $from, $to);

        $data = [
            'full' => $full,
            'range' => [
                'key' => $rangeKey,
                'upper' => $this->upper($rangeKey, $from),
                'note' => $this->note($from, $to),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'views' => $totals['views'],
            'delta' => $this->delta($spaceIds, $totals['views'], $from, $to, $rangeKey),
            'bars' => $series['bars'],
            'axis' => $series['axis'],
            'peak_note' => $this->peakNote($series, $from, $monthly),
            'spaces' => $this->spaceOptions($user),

            // Premium below this line.
            'unique' => null,
            'opened' => null,
            'downloads' => null,
            'summary' => null,
            'sources' => null,
            'files' => null,
            'viewers' => null,
            'crawlers' => null,
            'ai_refs' => null,
            'hours' => null,
            'approval' => null,
            'aeo' => null,
        ];

        if ($full) {
            $breakdown = Analytics::files($spaceIds, $from, $to);

            $data = array_merge($data, [
                'unique' => $totals['unique'],
                'opened' => array_sum(array_column($breakdown, 'views')),
                'downloads' => array_sum(array_column($breakdown, 'downloads')),
                'summary' => $this->summary($this->upper($rangeKey, $from), $totals),
                'sources' => Analytics::sources($spaceIds, $from, $to),
                'files' => $breakdown,
                'viewers' => Analytics::viewers($spaceIds, $from, $to),
                'crawlers' => Analytics::crawlers($spaceIds, $from, $to),
                'ai_refs' => Analytics::assistantReferrers($spaceIds, $from, $to),
                'hours' => Analytics::hours($spaceIds, $from, $to),
                'approval' => Analytics::approval($spaceIds),
                'aeo' => Analytics::answerReadiness($user),
            ]);
        }

        return response()->json(['data' => $data]);
    }

    /**
     * The window, and whether it is drawn as months rather than days.
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: CarbonInterface, 1: CarbonInterface, 2: bool}
     */
    private function window(string $range, array $validated): array
    {
        return match ($range) {
            'last_month' => [
                now()->subMonthNoOverflow()->startOfMonth(),
                now()->subMonthNoOverflow()->endOfMonth(),
                false,
            ],
            'this_year' => [now()->startOfYear(), now()->endOfYear(), true],
            // Date::parse, not Carbon::parse: the app runs on immutable
            // dates, and a mutable one here gets walked forward by the
            // series loop until the range reports its own end date.
            'custom' => [
                Date::parse($validated['from'] ?? now()->startOfMonth())->startOfDay(),
                Date::parse($validated['to'] ?? now())->endOfDay(),
                // A custom range longer than about three months is months.
                Date::parse($validated['from'] ?? now()->startOfMonth())
                    ->diffInDays(Date::parse($validated['to'] ?? now())) > 92,
            ],
            default => [now()->startOfMonth(), now()->endOfMonth(), false],
        };
    }

    private function upper(string $range, CarbonInterface $from): string
    {
        return match ($range) {
            'this_year' => $from->format('Y'),
            'custom' => strtoupper($from->format('j M Y')),
            default => strtoupper($from->format('F Y')),
        };
    }

    private function note(CarbonInterface $from, CarbonInterface $to): string
    {
        return $from->format('j M').' – '.$to->format('j M Y').' · '.config('app.timezone');
    }

    /**
     * How this range compares to the one before it — the same length,
     * ending where this one starts. Null-shaped ranges get an honest
     * sentence rather than a fake percentage.
     *
     * @param  list<int>  $spaceIds
     */
    private function delta(array $spaceIds, int $views, CarbonInterface $from, CarbonInterface $to, string $range): ?string
    {
        if ($range === 'this_year') {
            return null;
        }

        $length = (int) $from->diffInDays($to) + 1;
        $prevTo = $from->subDay()->endOfDay();
        $prevFrom = $prevTo->subDays($length - 1)->startOfDay();

        $previous = Analytics::totals($spaceIds, $prevFrom, $prevTo)['views'];

        if ($previous === 0) {
            return $views === 0 ? null : 'First views in this window.';
        }

        $change = (int) round((($views - $previous) / $previous) * 100);
        $sign = $change >= 0 ? '+' : '−';

        return $sign.abs($change).'% on '.$prevFrom->format('F').' — '
            .$this->count($previous, 'view');
    }

    /**
     * @param  array{bars: list<int>, axis: list<string>, views: int, peak_index: int|null}  $series
     */
    private function peakNote(array $series, CarbonInterface $from, bool $monthly): ?string
    {
        $index = $series['peak_index'];

        if ($index === null) {
            return null;
        }

        $day = $monthly
            ? $from->addMonths($index)->format('F')
            : $from->addDays($index)->format('j F');

        return 'Peak on '.$day.'.';
    }

    /**
     * @param  array{views: int, unique: int, crawlers: int}  $totals
     */
    private function summary(string $upper, array $totals): string
    {
        $period = ucfirst(strtolower($upper));

        return $period.' brought '.$this->count($totals['views'], 'view')
            .' from '.$this->count($totals['unique'], 'person', 'people')
            .', plus '.$this->count($totals['crawlers'], 'crawler hit').'.';
    }

    /**
     * "1 view", "2 views" — a sentence a person wrote would not say
     * "1 people", and this one is shown at 26px.
     */
    private function count(int $n, string $singular, ?string $plural = null): string
    {
        return $n.' '.($n === 1 ? $singular : ($plural ?? $singular.'s'));
    }

    /**
     * The Space filter's options — every Space the creator has, so the
     * dropdown does not silently omit one that has no traffic yet.
     *
     * @return list<array{id: string, name: string}>
     */
    private function spaceOptions(User $user): array
    {
        return $user->spaces()
            ->orderBy('title')
            ->get()
            ->map(fn (Space $space): array => [
                'id' => $space->ulid,
                'name' => $space->title,
            ])
            ->all();
    }
}
