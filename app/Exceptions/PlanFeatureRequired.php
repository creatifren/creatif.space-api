<?php

namespace App\Exceptions;

use App\Mcp\TokenContext;
use App\Models\User;
use App\Support\Trial;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A paid feature asked for on a plan without it. The app keeps its old 422
 * with the field error; a connected app or API key (bearer token) gets 402
 * with `plan_required` and a sentence an AI can pass on as it stands.
 */
class PlanFeatureRequired extends RuntimeException
{
    public const CODE = 'plan_required';

    public function __construct(
        public readonly string $feature,
        public readonly string $field,
        string $message,
        public readonly string $billingUrl,
    ) {
        parent::__construct($message);
    }

    public static function clientApprovals(User $user, string $field): self
    {
        $url = self::billingUrl();
        $next = Trial::eligible(Workspace::owner($user))
            ? 'Start the '.Trial::days().'-day Pro trial: '.$url
            : 'Upgrade: '.$url;

        return new self('client_approvals', $field, 'Client approval links are on Pro and Max. '.$next, $url);
    }

    public static function billingUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/settings?section=subscription';
    }

    public function render(Request $request): JsonResponse
    {
        $token = $request->attributes->get('mcp_token') instanceof TokenContext;

        return response()->json([
            'message' => $this->getMessage(),
            'code' => self::CODE,
            'feature' => $this->feature,
            'billing_url' => $this->billingUrl,
            'errors' => [$this->field => [$this->getMessage()]],
        ], $token ? 402 : 422);
    }
}
