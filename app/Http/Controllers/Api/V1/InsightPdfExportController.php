<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InsightReport;
use App\Models\User;
use App\Support\Insight\InsightClients;
use App\Support\Insight\InsightPdfBuilder;
use App\Support\Insight\InsightPeriod;
use App\Support\PlanQuota;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * The Insight PDF as a direct download — the app's Export menu.
 *
 *   GET  /api/v1/insight/export.pdf?from=YYYY-MM-DD&to=YYYY-MM-DD[&channels=ulid,ulid][&format=onepage|deck|details][&locale=en|id]
 *   POST /api/v1/insight/export-link        (same fields) → {url, expires_at, file_name}
 *   GET  /api/v1/insight/export.pdf/signed  (the url above; no session or token)
 *
 * Same parameters as the CSV export (from / to / channels), same builder as
 * the queued report (POST /insight/reports). Pro and Max: Free gets the
 * plans-v2 402 on all three, the signed one included (the plan is checked
 * again when the link is used, not only when it was made).
 */
class InsightPdfExportController extends Controller
{
    /** A link handed to a connected app works this long. */
    public const LINK_TTL_MINUTES = 15;

    public function download(Request $request, InsightPdfBuilder $builder): Response
    {
        $user = $request->user();
        PlanQuota::requireFeature($user, 'insight');

        return $this->pdf($user, $this->validated($request), $builder);
    }

    public function link(Request $request): JsonResponse
    {
        $user = $request->user();
        PlanQuota::requireFeature($user, 'insight');

        $input = $this->validated($request);
        $owner = Workspace::owner($user);
        $period = InsightPeriod::fromInput($input, '');
        $expires = now()->addMinutes(self::LINK_TTL_MINUTES);

        $url = URL::temporarySignedRoute('api.v1.insight.export.signed', $expires, [
            'u' => $user->ulid,
            'from' => $period->from->toDateString(),
            'to' => $period->to->toDateString(),
            'channels' => implode(',', $input['channel_ids']),
            'format' => $input['format'],
            'locale' => $input['locale'],
        ]);

        return response()->json([
            'url' => $url,
            'expires_at' => $expires->utc()->toIso8601String(),
            'file_name' => InsightClients::fileName(InsightClients::slug($owner), $period, $input['format']),
        ]);
    }

    /** The signed link: whoever holds it may download, until it expires. */
    public function signed(Request $request, InsightPdfBuilder $builder): Response
    {
        $user = User::query()->where('ulid', (string) $request->query('u'))->first();
        abort_if($user === null, 404);

        // Fail closed: a workspace that dropped to Free since the link was made.
        PlanQuota::requireFeature($user, 'insight');

        return $this->pdf($user, $this->validated($request), $builder);
    }

    /**
     * @param  array{from: string, to: string, channel_ids: list<string>, format: string, locale: string}  $input
     */
    private function pdf(User $user, array $input, InsightPdfBuilder $builder): Response
    {
        $owner = Workspace::owner($user);
        $period = InsightPeriod::fromInput($input, '');

        $result = $builder->build(
            $owner,
            InsightPdfBuilder::accounts($owner, $input['channel_ids']),
            $period,
            $input['format'],
            ['locale' => $input['locale'], 'branding' => true],
            InsightPdfBuilder::SYNC_THUMBNAIL_SECONDS,
        );

        $name = InsightClients::fileName(InsightClients::slug($owner), $period, $input['format']);

        return response($result['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
            'X-Page-Count' => (string) $result['pages'],
        ]);
    }

    /**
     * @return array{from: string, to: string, channel_ids: list<string>, format: string, locale: string}
     */
    private function validated(Request $request): array
    {
        $v = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'channels' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'format' => ['sometimes', 'string', 'in:'.implode(',', InsightReport::FORMATS)],
            'locale' => ['sometimes', 'string', 'in:'.implode(',', InsightReport::LOCALES)],
        ]);

        $user = $request->user() ?? User::query()->where('ulid', (string) $request->query('u'))->firstOrFail();
        $owner = Workspace::owner($user);

        // Same period rules as the report: ≤ 92 days, not in the future.
        InsightPeriod::fromInput(['from' => $v['from'], 'to' => $v['to']], '');

        $available = InsightClients::accounts($owner)->pluck('ulid')->all();
        $asked = array_values(array_filter(array_map('trim', explode(',', (string) ($v['channels'] ?? '')))));
        if ($asked !== [] && array_diff($asked, $available) !== []) {
            throw ValidationException::withMessages(['channels' => 'One of these channels is not connected to this workspace.']);
        }
        $channels = $asked !== [] ? $asked : $available;
        if ($channels === []) {
            throw ValidationException::withMessages(['channels' => 'Connect a channel first: there is nothing to report on.']);
        }

        $own = $user->getAttributes()['locale'] ?? null;

        return [
            'from' => $v['from'],
            'to' => $v['to'],
            'channel_ids' => $channels,
            'format' => $v['format'] ?? 'onepage',
            'locale' => $v['locale'] ?? (in_array($own, InsightReport::LOCALES, true) ? $own : 'en'),
        ];
    }
}
