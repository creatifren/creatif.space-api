<?php

namespace App\Http\Middleware;

use App\Exceptions\PlanFeatureRequired;
use App\Mcp\TokenContext;
use App\Models\McpAuditLog;
use App\Support\PlanQuota;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The fence around bearer-token access (OAuth tokens and API keys).
 *
 * A request signed in with the SPA cookie passes straight through. A token
 * request may only reach the routes in config('mcp.routes'), with the scope
 * listed there; draft-scope writes must be drafts; a route listed in
 * config('mcp.route_features') also needs that plan feature (402
 * plan_required, the same answer as everywhere else). Every token request is
 * rate limited per token and leaves an audit row — route, tool, client,
 * status — never content.
 */
class EnforceTokenScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = $request->attributes->get('mcp_token');
        if (! $context instanceof TokenContext) {
            return $next($request);
        }

        $started = hrtime(true);
        $route = $request->route()?->getName();
        // Route names are dotted: look the name up as a key, not a config path.
        $needed = $route === null ? null : (((array) config('mcp.routes'))[$route] ?? null);
        $feature = $route === null ? null : (((array) config('mcp.route_features'))[$route] ?? null);

        $limit = max(1, (int) config('mcp.rate_limit_per_minute'));
        $bucket = 'mcp-token:'.$context->key();

        $response = match (true) {
            $needed === null => $this->deny(
                403,
                'not_available',
                'This isn\'t available to connected apps or API keys yet. They can read channels, posts, files and Credits, upload media and save drafts.',
            ),
            ! $context->can($needed) => $this->deny(
                403,
                'insufficient_scope',
                "This needs the \"{$needed}\" permission. Reconnect the app (or create an API key) with \"{$needed}\" allowed.",
                $needed,
            ),
            $route === 'api.v1.social-posts.store' && ! $this->isDraftOnly($request) => $this->deny(
                403,
                'draft_only',
                'Connected apps can only save drafts. Open the draft in Creatif Space to schedule or send it.',
            ),
            is_string($feature) && ! PlanQuota::hasFeature($context->user, $feature) => PlanFeatureRequired::feature($context->user, $feature)->render(),
            RateLimiter::tooManyAttempts($bucket, $limit) => $this->tooMany($bucket),
            default => null,
        };

        if ($response === null) {
            RateLimiter::hit($bucket, 60);
            $response = $next($request);
            $response->headers->set('X-RateLimit-Limit', (string) $limit);
            $response->headers->set('X-RateLimit-Remaining', (string) RateLimiter::remaining($bucket, $limit));
        }

        $this->audit($request, $context, $route, $response->getStatusCode(), intdiv(hrtime(true) - $started, 1_000_000));

        return $response;
    }

    /**
     * A draft and nothing else: `draft` true, no approval request. A
     * scheduled_at rides along as a field of the draft — saving a draft
     * never reaches the provider.
     */
    private function isDraftOnly(Request $request): bool
    {
        return $request->boolean('draft') && ! $request->filled('approval');
    }

    private function deny(int $status, string $error, string $message, ?string $scope = null): JsonResponse
    {
        $response = response()->json(['message' => $message, 'error' => $error], $status);

        if ($error === 'insufficient_scope') {
            $response->headers->set('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="'.$scope.'"');
        }

        return $response;
    }

    private function tooMany(string $bucket): JsonResponse
    {
        $retry = RateLimiter::availableIn($bucket);

        return response()->json([
            'message' => "Too many requests with this token. Try again in {$retry} seconds.",
            'error' => 'rate_limited',
        ], 429, ['Retry-After' => (string) $retry]);
    }

    private function audit(Request $request, TokenContext $context, ?string $route, int $status, int $durationMs): void
    {
        $tool = $request->header('X-MCP-Tool');
        $tool = is_string($tool) && preg_match('/^[a-z0-9_.-]{1,64}$/i', $tool) === 1 ? $tool : null;

        // An OAuth client is named by its registration; an API key by the
        // MCP client the server saw (Claude Desktop, Cursor…) or its own name.
        $clientName = $context->clientName;
        if ($context->kind === TokenContext::KIND_API_KEY) {
            $header = $request->header('X-MCP-Client');
            if (is_string($header) && $header !== '') {
                $clientName = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $header) ?? '', 0, 191);
            }
        }

        try {
            McpAuditLog::query()->create([
                'user_id' => $context->user->id,
                'token_kind' => $context->kind,
                'token_id' => $context->tokenId,
                'client_id' => $context->clientId,
                'client_name' => $clientName === null ? null : mb_substr($clientName, 0, 191),
                'tool' => $tool,
                'route' => $route,
                'method' => $request->method(),
                'status' => $status,
                'duration_ms' => max(0, $durationMs),
                'ip' => $request->ip(),
            ]);
        } catch (Throwable $e) {
            // The audit trail must never take the answer down with it.
            Log::warning('mcp.audit_failed', ['error' => $e->getMessage()]);
        }
    }
}
