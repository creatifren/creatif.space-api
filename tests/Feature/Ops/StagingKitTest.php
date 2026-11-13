<?php

/*
 * ops/staging: the staging deploy kit QA installs (Creem TEST mode). The
 * repository is public, so the kit holds no secret, no IP address and no
 * server path beyond /www/wwwroot/staging-*; the env template keeps every
 * outside service off, and every response from staging says noindex.
 */

function stagingFiles(): array
{
    $dir = base_path('ops/staging');
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        $files[substr($f->getPathname(), strlen($dir) + 1)] = file_get_contents($f->getPathname());
    }
    ksort($files);

    return $files;
}

/** @return array<string, string> */
function stagingEnv(): array
{
    preg_match_all('/^([A-Z][A-Z0-9_]*)=(.*)$/m', file_get_contents(base_path('ops/staging/.env.staging.example')), $m, PREG_SET_ORDER);

    return collect($m)->mapWithKeys(fn ($row) => [$row[1] => trim($row[2], '"')])->all();
}

it('ships the deploy script, env template, README and staging-* supervisor programs', function () {
    expect(array_keys(stagingFiles()))->toBe([
        '.env.staging.example',
        'README.md',
        'deploy-staging-api.sh',
        'supervisor/staging-api-scheduler.conf',
        'supervisor/staging-api-worker.conf',
        'supervisor/staging-mcp.conf',
    ]);

    foreach (stagingFiles() as $name => $text) {
        if (str_starts_with($name, 'supervisor/')) {
            preg_match_all('/^\[program:([^\]]+)\]/m', $text, $m);
            expect($m[1])->not->toBeEmpty();
            foreach ($m[1] as $program) {
                expect($program)->toStartWith('staging-');
            }
        }
    }
});

it('keeps Creem in test mode and every outside service off', function () {
    $env = stagingEnv();

    expect($env)->toMatchArray([
        'APP_ENV' => 'staging',
        'APP_DEBUG' => 'false',
        'APP_NOINDEX' => 'true',
        'CREEM_TEST_MODE' => 'true',
        'MAIL_MAILER' => 'log',
    ]);
    expect($env['APP_URL'])->toStartWith('https://staging-')
        ->and($env['FRONTEND_URL'])->toStartWith('https://staging-');

    $empty = ['APP_KEY', 'DB_PASSWORD', 'CREEM_API_KEY', 'CREEM_WEBHOOK_SECRET', 'CREEM_FOUNDING_CODE',
        'OUTSTAND_API_KEY', 'OUTSTAND_WEBHOOK_SECRET', 'POSTFORME_API_KEY', 'AI_API_KEY', 'AI_BASE_URL',
        'POSTHOG_API_KEY', 'POSTHOG_PERSONAL_API_KEY', 'RESEND_API_KEY', 'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY', 'GOOGLE_CLIENT_SECRET', 'PASSPORT_PRIVATE_KEY', 'MCP_INTROSPECT_SECRET', 'HEALTH_TOKEN'];
    foreach ($empty as $key) {
        expect($env)->toHaveKey($key)->and($env[$key])->toBe('', $key);
    }
    foreach ($env as $key => $value) {
        if (str_starts_with($key, 'CREEM_PRODUCT_')) {
            expect($value)->toBe('', $key);
        }
    }
});

it('names only settings the app reads', function () {
    preg_match_all('/^#?\s*([A-Z][A-Z0-9_]+)=/m', file_get_contents(base_path('.env.example')), $m);
    $known = [...$m[1], 'APP_NOINDEX', 'SESSION_COOKIE', 'SESSION_SECURE_COOKIE', 'SANCTUM_STATEFUL_DOMAINS'];

    expect(array_values(array_diff(array_keys(stagingEnv()), $known)))->toBe([]);
});

it('holds no secret, IP address or server path beyond /www/wwwroot/staging-*', function () {
    foreach (stagingFiles() as $name => $text) {
        expect($text)->not->toMatch('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $name)
            ->not->toMatch('/\b(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{8,}/', $name)
            ->not->toMatch('/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $name);

        preg_match_all('#/(?:www|home|root|etc|var|opt|srv)/[^\s"\'`)]*#', $text, $paths);
        foreach ($paths[0] as $path) {
            expect($path)->toMatch('#^/www/wwwroot/staging-[a-z]+(?:/|$)#', "{$name}: {$path}");
        }
    }
});

it('refuses to deploy anything but staging with Creem in test mode', function () {
    $script = stagingFiles()['deploy-staging-api.sh'];

    expect($script)->toStartWith("#!/usr/bin/env bash\n")
        ->toContain('set -euo pipefail')
        ->toContain('[ "$(env_of APP_ENV)" = "staging" ]')
        ->toContain('[ "$(env_of CREEM_TEST_MODE)" = "true" ]')
        ->toContain('artisan migrate --force')
        ->toContain('artisan optimize')
        ->toContain('artisan queue:restart');
    expect(is_executable(base_path('ops/staging/deploy-staging-api.sh')))->toBeTrue();
});

it('says noindex on every response when APP_NOINDEX is on, and not otherwise', function () {
    $this->getJson('/api/v1/plans')->assertHeaderMissing('X-Robots-Tag');

    config(['app.noindex' => true]);
    $this->getJson('/api/v1/plans')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->getJson('/api/v1/no-such-thing')->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});
