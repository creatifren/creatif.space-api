<?php

/*
 * Seeds the MCP contract smoke (scripts/mcp-contract-smoke.sh): one Free
 * user, onboarded, and two API keys minted the way ApiKeyController does
 * (Sanctum personal_access_tokens, SHA-256 only): one with every scope, one
 * read-only. Prints {"full": "...", "read": "..."} for the smoke to use.
 * Test database only: refuses to run outside APP_ENV=testing/local.
 */

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! in_array(app()->environment(), ['testing', 'local'], true)) {
    fwrite(STDERR, "mcp-contract-seed: refused, APP_ENV is ".app()->environment()."\n");
    exit(1);
}

$user = User::factory()->onboarded()->create(['email' => 'mcp-contract@example.test', 'name' => 'MCP Contract']);

$mint = function (string $name, array $scopes) use ($user): string {
    $plain = config('mcp.api_keys.prefix').Str::random(48);
    $user->tokens()->create(['name' => $name, 'token' => hash('sha256', $plain), 'abilities' => $scopes, 'expires_at' => null]);

    return $plain;
};

echo json_encode([
    'full' => $mint('contract-full', array_keys((array) config('mcp.scopes'))),
    'read' => $mint('contract-read', ['read']),
]), "\n";
