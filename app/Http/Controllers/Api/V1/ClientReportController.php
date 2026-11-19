<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\ClientLabel;
use App\Models\ClientReport;
use App\Notifications\ClientReportReady;
use App\Support\ClientReportBuilder;
use App\Support\PlanQuota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

/**
 * Monthly client reports (prototype v7 › Insight › Reports, Preferences ›
 * Report emails). One report per client per month, built from Insight for
 * the client's accounts; shared by link (Link only by default) and emailed
 * to the client's approver on the owner's schedule (SendClientReports).
 */
class ClientReportController extends Controller
{
    public function __construct(private ClientReportBuilder $builder) {}

    /** Preferences › Report emails. */
    public function settings(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->settingsArray($request)]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            // 1–28: every month has the day.
            'send_day' => ['sometimes', 'integer', 'min:1', 'max:28'],
            'send_time' => ['sometimes', 'string', 'regex:/^([01]\d|2[0-3]):00$/'],
            'from_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'clients' => ['sometimes', 'array', 'max:200'],
            'clients.*.id' => ['required_with:clients', 'string', 'max:26'],
            'clients.*.report_email' => ['required_with:clients', 'boolean'],
        ]);
        $user = $request->user();
        if (($validated['enabled'] ?? false) === true) {
            PlanQuota::requireFeature($user, 'insight', 'enabled');
        }

        $user->forceFill(array_filter([
            'client_reports_enabled' => $validated['enabled'] ?? null,
            'report_send_day' => $validated['send_day'] ?? null,
            'report_send_time' => $validated['send_time'] ?? null,
        ], fn ($v) => $v !== null) + (array_key_exists('from_name', $validated) ? ['report_from_name' => $validated['from_name']] : []))->save();

        foreach ($validated['clients'] ?? [] as $c) {
            $user->clientLabels()->where('ulid', $c['id'])->update(['report_email' => $c['report_email']]);
        }

        return response()->json(['data' => $this->settingsArray($request)]);
    }

    /**
     * Insight › Reports: this month's reports, scheduled per client, and
     * the ones already sent (newest first).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $month = now($user->timezone ?: config('app.timezone'))->format('Y-m');
        $labels = $user->clientLabels()->orderBy('name')->get();
        $sent = ClientReport::query()->where('user_id', $user->id)->whereNotNull('sent_at')->with('clientLabel')->latest('sent_at')->limit(50)->get();

        return response()->json(['data' => [
            'automatic' => (bool) $user->client_reports_enabled,
            'send_on' => $this->sendOn($request),
            'scheduled' => $labels->map(fn (ClientLabel $l) => [
                'client' => $l->toBadge(),
                'month' => $month,
                'to' => $l->approver_name ?: $l->approver_email,
                'will_send' => (bool) $user->client_reports_enabled && $l->report_email && $l->approver_email !== null,
            ])->values()->all(),
            'sent' => $sent->map(fn (ClientReport $r) => [
                'id' => $r->ulid,
                'client' => $r->clientLabel?->toBadge(),
                'month' => $r->month,
                'sent_at' => $r->sent_at?->toIso8601String(),
                'sent_to' => $r->sent_to,
                'open_count' => $r->open_count,
                'url' => $r->url(),
            ])->values()->all(),
        ]]);
    }

    /** Open (or make) the report of one client for one month. */
    public function store(Request $request): JsonResponse
    {
        PlanQuota::requireFeature($request->user(), 'insight', 'client_id');
        $validated = $request->validate([
            'client_id' => ['required', 'string', 'max:26'],
            'month' => ['required', 'date_format:Y-m', 'before_or_equal:'.now()->format('Y-m')],
        ]);
        $label = $request->user()->clientLabels()->where('ulid', $validated['client_id'])->first();
        abort_if($label === null, 404);

        $report = ClientReport::query()->firstOrCreate(
            ['client_label_id' => $label->id, 'month' => $validated['month']],
            ['user_id' => $request->user()->id],
        );

        return response()->json(['data' => $this->builder->build($report->load(['user', 'clientLabel']))], $report->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, ClientReport $clientReport): JsonResponse
    {
        Gate::allowIf(fn ($user) => $clientReport->user_id === $user->id);

        return response()->json(['data' => $this->builder->build($clientReport->load(['user', 'clientLabel']))]);
    }

    /** Edit the summary; change who can see it. */
    public function update(Request $request, ClientReport $clientReport): JsonResponse
    {
        Gate::allowIf(fn ($user) => $clientReport->user_id === $user->id);
        $validated = $request->validate([
            'summary' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'visibility' => ['sometimes', 'string', Rule::in(ClientReport::VISIBILITY)],
        ]);
        if (array_key_exists('summary', $validated)) {
            $clientReport->forceFill(['summary' => $validated['summary'], 'summary_edited_at' => $validated['summary'] === null ? null : now()]);
        }
        if (isset($validated['visibility'])) {
            $clientReport->visibility = $validated['visibility'];
        }
        $clientReport->save();

        return response()->json(['data' => $this->builder->build($clientReport->load(['user', 'clientLabel']))]);
    }

    /** "Send now": email the report link to the client's approver. */
    public function send(Request $request, ClientReport $clientReport): JsonResponse
    {
        Gate::allowIf(fn ($user) => $clientReport->user_id === $user->id);
        $to = $clientReport->clientLabel?->approver_email;
        if ($to === null) {
            return response()->json(['message' => 'Add an approver email to this client first (Preferences › Clients).', 'reason' => 'no_approver'], 422);
        }
        self::deliver($this->builder, $clientReport->load(['user', 'clientLabel']));

        return response()->json(['data' => $this->builder->build($clientReport->fresh(['user', 'clientLabel']))]);
    }

    /** Turn the share link off for good. */
    public function revoke(Request $request, ClientReport $clientReport): JsonResponse
    {
        Gate::allowIf(fn ($user) => $clientReport->user_id === $user->id);
        $clientReport->forceFill(['revoked_at' => $clientReport->revoked_at ?? now()])->save();

        return response()->json(null, 204);
    }

    /**
     * The client's view: no sign-in, the token is the key. Private or turned
     * off answers like a dead approval link (state + editor), never a bare 404.
     */
    public function showPublic(Request $request, string $token): JsonResponse
    {
        $report = ClientReport::query()->where('token_hash', ClientReport::hashToken($token))->with(['user', 'clientLabel'])->first();
        if ($report === null || $report->user === null || $report->user->status === UserStatus::PendingDeletion) {
            abort(404);
        }
        if ($report->revoked_at !== null || $report->visibility === 'private') {
            return response()->json(['message' => 'This report link was turned off.', 'state' => 'revoked', 'editor' => $report->user->name], 409);
        }
        if ($request->user('sanctum')?->id !== $report->user_id) {
            $report->increment('open_count', 1, ['last_opened_at' => now()]);
        }

        $data = $this->builder->build($report);
        unset($data['sent_to'], $data['open_count'], $data['notes']);

        return response()->json(['data' => $data]);
    }

    /** Build (if needed) and email one report; marks it sent. */
    public static function deliver(ClientReportBuilder $builder, ClientReport $report): void
    {
        $label = $report->clientLabel;
        if ($label?->approver_email === null) {
            return;
        }
        $data = $builder->build($report);
        Notification::route('mail', $label->approver_email)->notify(new ClientReportReady(
            url: $report->url(),
            client: $label->name,
            period: $data['period']['label'],
            preparedBy: (string) $data['prepared_by'],
            summary: (string) ($data['summary'] ?? ''),
        ));
        $report->forceFill(['sent_at' => now(), 'sent_to' => $label->approver_email])->save();
    }

    /** @return array<string, mixed> */
    private function settingsArray(Request $request): array
    {
        $user = $request->user();

        return [
            'enabled' => (bool) $user->client_reports_enabled,
            'send_day' => (int) ($user->report_send_day ?? 1),
            'send_time' => $user->report_send_time ?? '09:00',
            'timezone' => $user->timezone ?: config('app.timezone'),
            'from_name' => $user->report_from_name,
            'from_name_default' => $user->name.' · Creatif Space',
            'available' => PlanQuota::hasFeature($user, 'insight'),
            'clients' => $user->clientLabels()->orderBy('name')->get()->map(fn (ClientLabel $l) => [
                ...$l->toBadge(),
                'approver_email' => $l->approver_email,
                'approver_name' => $l->approver_name,
                'report_email' => $l->report_email,
            ])->values()->all(),
        ];
    }

    private function sendOn(Request $request): string
    {
        $user = $request->user();
        $day = (int) ($user->report_send_day ?? 1);
        $suffix = in_array($day % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$day % 10] ?? 'th');

        return "{$day}{$suffix} of the month, ".($user->report_send_time ?? '09:00');
    }
}
