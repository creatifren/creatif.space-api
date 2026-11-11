<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\BuildInsightReport;
use App\Models\InsightReport;
use App\Support\Insight\InsightAccess;
use App\Support\Insight\InsightClients;
use App\Support\Insight\InsightPeriod;
use App\Support\PlanQuota;
use App\Support\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Insight → Export PDF (design pick #8). POST queues a build and answers
 * 202 at once; the sheet polls GET until `done` and opens `download_url`.
 * Contract: see the PR that added this file, or tests/Feature/InsightReportApiTest.php.
 */
class InsightReportController extends Controller
{
    public function clients(Request $request): JsonResponse
    {
        return response()->json(['data' => InsightClients::for($request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        // Pro / Max (plans v2): 402 plan_required, feature "insight".
        PlanQuota::requireFeature($user, 'insight');

        $validated = $request->validate([
            'client_id' => ['required', 'string', 'max:64'],
            'period' => ['required', 'array'],
            'period.month' => ['required_without_all:period.from,period.to', 'nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'period.from' => ['required_without:period.month', 'nullable', 'date_format:Y-m-d'],
            'period.to' => ['required_without:period.month', 'nullable', 'date_format:Y-m-d'],
            'format' => ['required', 'string', 'in:'.implode(',', InsightReport::FORMATS)],
            'channel_ids' => ['sometimes', 'array', 'max:50'],
            'channel_ids.*' => ['string', 'distinct'],
            'show_logo' => ['sometimes', 'boolean'],
            'hide_branding' => ['sometimes', 'boolean'],
            'next_month_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'locale' => ['sometimes', 'string', 'in:'.implode(',', InsightReport::LOCALES)],
        ]);

        $owner = InsightClients::resolve($user, $validated['client_id']);
        if ($owner === null) {
            throw ValidationException::withMessages(['client_id' => 'Unknown client.']);
        }

        $period = $this->period($validated['period']);

        $available = InsightClients::accounts($owner)->pluck('ulid')->all();
        $channels = array_values($validated['channel_ids'] ?? $available);
        if ($channels === []) {
            throw ValidationException::withMessages(['channel_ids' => 'Connect a channel first: there is nothing to report on.']);
        }
        if (array_diff($channels, $available) !== []) {
            throw ValidationException::withMessages(['channel_ids' => 'One of these channels is not connected to this client.']);
        }

        // The user's own language unless the sheet says otherwise.
        $own = $user->getAttributes()['locale'] ?? null;
        $locale = $validated['locale'] ?? (in_array($own, InsightReport::LOCALES, true) ? $own : 'en');

        $report = InsightReport::create([
            'user_id' => $user->id,
            'owner_id' => $owner->id,
            'client_id' => $owner->ulid,
            'format' => $validated['format'],
            'locale' => $locale,
            'period_from' => $period->from->toDateString(),
            'period_to' => $period->to->toDateString(),
            'options' => [
                'period_kind' => $period->kind,
                'channel_ids' => $channels,
                'show_logo' => (bool) ($validated['show_logo'] ?? false),
                // Free always carries "Made with Creatif Space".
                'hide_branding' => (bool) ($validated['hide_branding'] ?? false) && InsightAccess::canHideBranding($user),
                'next_month_notes' => $validated['next_month_notes'] ?? null,
            ],
            'status' => InsightReport::STATUS_QUEUED,
            'file_name' => InsightClients::fileName(InsightClients::slug($owner), $period, $validated['format']),
        ]);

        BuildInsightReport::dispatch($report);

        return response()->json($this->body($report), 202);
    }

    public function show(Request $request, InsightReport $insightReport): JsonResponse
    {
        // Anyone in the workspace may fetch it; nobody else learns it exists.
        abort_unless($insightReport->owner_id === Workspace::owner($request->user())->id, 404);

        return response()->json($this->body($insightReport));
    }

    /**
     * @return array<string, mixed>
     */
    private function body(InsightReport $report): array
    {
        $done = $report->status === InsightReport::STATUS_DONE;

        return [
            'id' => $report->ulid,
            'status' => $report->status,
            'format' => $report->format,
            'file_name' => $report->file_name,
            'download_url' => $report->downloadUrl(),
            'download_expires_at' => $done && $report->downloadUrl() !== null
                ? now()->addMinutes(InsightReport::URL_TTL_MINUTES)->toIso8601String()
                : null,
            // Reserved: public share links are not built yet.
            'share_url' => null,
            'page_count' => $report->page_count,
            'size_bytes' => $report->size_bytes,
            'failure_reason' => $report->failure_reason,
            'expired' => $done && $report->expired(),
            'created_at' => $report->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function period(array $input): InsightPeriod
    {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();

        if (! empty($input['month'])) {
            $period = InsightPeriod::month($input['month']);
            if ($period->from->greaterThan($today)) {
                throw ValidationException::withMessages(['period.month' => 'That month has not started yet.']);
            }

            return $period;
        }

        $period = InsightPeriod::range((string) $input['from'], (string) $input['to']);

        if ($period->to->lessThan($period->from)) {
            throw ValidationException::withMessages(['period.to' => 'The end date is before the start date.']);
        }
        if ($period->from->greaterThan($today)) {
            throw ValidationException::withMessages(['period.from' => 'That period has not started yet.']);
        }
        if ($period->days() > InsightPeriod::MAX_DAYS) {
            throw ValidationException::withMessages(['period.to' => 'A report covers at most '.InsightPeriod::MAX_DAYS.' days.']);
        }

        return $period;
    }
}
