<?php

namespace App\Exceptions;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A plan limit or a paid feature, refused. One answer everywhere — the app,
 * API keys, connected apps (MCP) alike:
 *
 *   402 {message, reason: "plan_required", feature, required_plan, limit?}
 *
 * plus `code` (= reason, what older clients read), `billing_url` and the
 * field error under `errors`, so a form can still show it next to a field.
 * The message is written to be passed on as it stands: it names the limit
 * and carries the upgrade link.
 *
 * Features: schedule_window, queue_limit, approval_clients, and every
 * feature gate in PlanQuota::FEATURES.
 */
class PlanFeatureRequired extends RuntimeException
{
    public const CODE = 'plan_required';

    /** What each feature is called in a sentence ("… is on Pro and Max."). */
    private const NAMES = [
        'approval_clients' => 'Approval links for more than one client',
        'ai_caption' => 'Improve with AI and Add hashtags',
        'insight' => 'Insights and analytics',
        'spaces' => 'Creating and editing Spaces and your link in bio',
        'alt_text' => 'AI alt text',
        'ai_reply_suggestions' => 'AI reply suggestions',
        'comment_to_dm' => 'Comment-to-DM',
    ];

    public function __construct(
        public readonly string $feature,
        public readonly string $field,
        string $message,
        public readonly string $billingUrl,
        public readonly string $requiredPlan = 'pro',
        public readonly ?int $limit = null,
    ) {
        parent::__construct($message);
    }

    /** A feature gate (PlanQuota::FEATURES) on a plan without it. */
    public static function feature(User $user, string $feature, string $field = 'plan'): self
    {
        $name = self::NAMES[$feature] ?? ucfirst(str_replace('_', ' ', $feature));

        return self::make($user, $feature, $field, "{$name} is on Pro and Max.");
    }

    public static function scheduleWindow(User $user, int $days, string $field = 'scheduled_at'): self
    {
        return self::make($user, 'schedule_window', $field,
            "On Free, posts can be scheduled up to {$days} days ahead. Scheduling further out is on Pro and Max.", $days);
    }

    public static function queueLimit(User $user, int $limit, string $field = 'scheduled_at'): self
    {
        return self::make($user, 'queue_limit', $field,
            "On Free, up to {$limit} posts can be queued (scheduled or waiting for approval) at once. More is on Pro and Max.", $limit);
    }

    public static function approvalClients(User $user, int $limit, string $field = 'client_name'): self
    {
        $clients = $limit === 1 ? 'one client' : "{$limit} clients";

        return self::make($user, 'approval_clients', $field,
            "On Free, approval links are for {$clients} at a time. Approval for more clients is on Pro and Max.", $limit);
    }

    private static function make(User $user, string $feature, string $field, string $sentence, ?int $limit = null): self
    {
        $url = self::billingUrl();

        return new self($feature, $field, $sentence.' Upgrade: '.$url, $url, 'pro', $limit);
    }

    public static function billingUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/settings?section=subscription';
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return [
            'message' => $this->getMessage(),
            'reason' => self::CODE,
            'feature' => $this->feature,
            'required_plan' => $this->requiredPlan,
            ...($this->limit !== null ? ['limit' => $this->limit] : []),
            'code' => self::CODE,
            'billing_url' => $this->billingUrl,
            'errors' => [$this->field => [$this->getMessage()]],
        ];
    }

    public function render(): JsonResponse
    {
        return response()->json($this->body(), 402);
    }
}
