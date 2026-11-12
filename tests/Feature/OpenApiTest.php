<?php

use App\Support\OpenApi;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
 * GET /api/v1/openapi.json — built from the route table. Fails when a v1
 * route has no summary in resources/openapi/operations.php (document it)
 * or when a summary names a route that no longer exists.
 */

it('documents every public v1 route — add a line to resources/openapi/operations.php for a new one', function () {
    $summaries = OpenApi::summaries();
    $missing = collect(OpenApi::routes())
        ->map(fn (RoutingRoute $r) => $r->getName())
        ->reject(fn (string $name) => isset($summaries[$name]))
        ->values()->all();

    expect($missing)->toBe([]);
});

it('has no summary for a route that does not exist', function () {
    $names = collect(OpenApi::routes())->map(fn (RoutingRoute $r) => $r->getName())->all();
    expect(array_values(array_diff(array_keys(OpenApi::summaries()), $names)))->toBe([]);
});

it('leaves out only webhooks and MCP introspection, each with a reason', function () {
    $left = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $r) => str_starts_with($r->uri(), 'api/v1/') && OpenApi::excluded($r->getName()))
        ->map(fn (RoutingRoute $r) => $r->uri())
        ->reject(fn (string $uri) => str_starts_with($uri, 'api/v1/webhooks/') || $uri === 'api/v1/oauth/introspect')
        ->values()->all();

    expect($left)->toBe([]);
    foreach (OpenApi::EXCLUDED as $reason) {
        expect($reason)->not->toBe('');
    }
});

it('serves OpenAPI 3.1 without sign-in, one operation per route and method', function () {
    $doc = $this->getJson('/api/v1/openapi.json')->assertOk()->assertJsonPath('openapi', '3.1.0')->json();

    $operations = collect($doc['paths'])->flatMap(fn ($ops) => array_values($ops));
    $expected = collect(OpenApi::routes())->sum(fn (RoutingRoute $r) => count(array_diff($r->methods(), ['HEAD'])));
    expect($operations)->toHaveCount($expected)
        ->and($operations->pluck('operationId')->duplicates()->all())->toBe([]);

    expect($doc['paths'])->not->toHaveKey('/webhooks/creem')->not->toHaveKey('/oauth/introspect');
});

it('describes auth, token scope, path parameters and rate limits from the routes', function () {
    $doc = OpenApi::document();

    $audit = $doc['paths']['/social-approval-links/{socialApprovalLink}/audit.csv']['get'];
    expect($audit['security'])->toBe([['bearerAuth' => []], ['sessionCookie' => []]])
        ->and($audit['x-token-scope'])->toBe('read')
        ->and($audit['parameters'][0])->toMatchArray(['name' => 'socialApprovalLink', 'in' => 'path', 'required' => true])
        ->and($audit['x-rate-limit'])->toBe('30,1');

    $export = $doc['paths']['/me/export']['post'];
    expect($export['x-token-scope'])->toBeNull();

    $plans = $doc['paths']['/plans']['get'];
    expect($plans['security'])->toBe([]);

    expect($doc['paths']['/me/export/{export}/download']['get']['description'])->toContain('Signed URL');
});
