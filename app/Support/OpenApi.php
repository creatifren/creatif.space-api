<?php

namespace App\Support;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;

/**
 * The OpenAPI 3.1 document for the public v1 API, built from the route
 * table so it cannot drift: every path, method, path parameter, auth
 * requirement, rate limit and API-token scope comes from the routes and
 * config('mcp.routes'). The one hand-written part is the summary per
 * operation (resources/openapi/operations.php); OpenApiTest fails when a
 * v1 route has none.
 */
final class OpenApi
{
    /** Route names (or name prefixes ending in '.') left out on purpose, and why. */
    public const EXCLUDED = [
        'api.v1.webhooks.' => 'provider webhooks (signed by the provider, not for clients)',
        'api.v1.oauth.introspect' => 'token introspection for our own MCP server',
    ];

    public static function excluded(?string $name): bool
    {
        if ($name === null) {
            return true;
        }
        foreach (array_keys(self::EXCLUDED) as $rule) {
            if ($name === $rule || (str_ends_with($rule, '.') && str_starts_with($name, $rule))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Route> the public v1 routes the document describes */
    public static function routes(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => str_starts_with($r->uri(), 'api/v1/') && ! self::excluded($r->getName()))
            ->values()
            ->all();
    }

    /** @return array<string, string> route name => summary */
    public static function summaries(): array
    {
        /** @var array<string, string> $map */
        $map = require resource_path('openapi/operations.php');

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    public static function document(): array
    {
        $summaries = self::summaries();
        $scopes = (array) config('mcp.routes', []);
        $paths = [];

        foreach (self::routes() as $route) {
            $name = (string) $route->getName();
            $path = '/'.Str::after($route->uri(), 'api/v1/');
            $middleware = collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m));
            $authenticated = $middleware->contains(fn (string $m) => str_starts_with($m, 'auth:'));
            $signed = $middleware->contains(fn (string $m) => $m === 'signed' || str_starts_with($m, 'signed:'));
            $throttle = $middleware->first(fn (string $m) => str_starts_with($m, 'throttle:'));

            $operation = [
                'operationId' => $name,
                'summary' => $summaries[$name] ?? $name,
                'tags' => [Str::before(ltrim(Str::after($path, '/'), '/').'/', '/') ?: 'root'],
            ];
            $parameters = [];
            foreach ($route->parameterNames() as $param) {
                $parameters[] = ['name' => $param, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
            }
            if ($parameters !== []) {
                $operation['parameters'] = $parameters;
            }
            $operation['security'] = $authenticated ? [['bearerAuth' => []], ['sessionCookie' => []]] : [];
            if ($authenticated) {
                // An API key / OAuth token reaches only the routes in mcp.routes.
                $operation['x-token-scope'] = $scopes[$name] ?? null;
            }
            if ($signed) {
                $operation['description'] = 'Signed URL: open the link exactly as it was handed out (signature and expires in the query).';
            }
            if (is_string($throttle)) {
                $operation['x-rate-limit'] = Str::after($throttle, 'throttle:');
            }
            $operation['responses'] = self::responses($authenticated);

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $paths[$path][strtolower($method)] = $operation;
            }
        }
        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'creatif.space API',
                'version' => 'v1',
                'description' => 'The creatif.space v1 API. Authenticate with an API key or OAuth token (Authorization: Bearer) — it reaches only operations with an x-token-scope, and only with that scope — or with the app session. Errors are JSON {message, …}; 422 adds errors per field; 429 adds retry_after. MCP server: https://mcp.creatif.space.',
            ],
            'servers' => [['url' => rtrim((string) config('app.url'), '/').'/api/v1']],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'API key (Settings → API keys) or OAuth access token'],
                    'sessionCookie' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => (string) config('session.cookie'), 'description' => 'The app session (first-party only)'],
                ],
                'schemas' => [
                    'Error' => [
                        'type' => 'object',
                        'required' => ['message'],
                        'properties' => [
                            'message' => ['type' => 'string'],
                            'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                            'retry_after' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<int|string, mixed> status code (an int key once PHP sees '401') or '2XX' => response */
    private static function responses(bool $authenticated): array
    {
        $error = fn (string $d) => ['description' => $d, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
        $out = ['2XX' => ['description' => 'Success']];
        if ($authenticated) {
            $out['401'] = $error('Not signed in, or the token is invalid');
            $out['403'] = $error('Not allowed (e.g. token without the scope)');
        }
        $out['404'] = $error('Not found (another workspace’s id is 404 too)');
        $out['422'] = $error('Invalid input');
        $out['429'] = $error('Rate limited; retry_after says when to try again');

        return $out;
    }
}
