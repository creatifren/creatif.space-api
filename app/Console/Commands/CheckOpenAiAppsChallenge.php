<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Before a ChatGPT Apps submission: is the domain-verification token served?
 *
 *   php artisan mcp:check-openai-challenge
 *   php artisan mcp:check-openai-challenge --url=https://mcp.staging.creatif.space/.well-known/openai-apps-challenge
 *
 * Fetches GET {MCP origin}/.well-known/openai-apps-challenge and checks it is
 * 200 text/plain and not empty. If OPENAI_APPS_CHALLENGE is also set in this
 * environment, it says whether the served token matches it — comparing
 * hashes, never printing either value. The token itself is never shown.
 */
class CheckOpenAiAppsChallenge extends Command
{
    protected $signature = 'mcp:check-openai-challenge {--url= : The challenge URL (default: the MCP server origin from mcp.resource_url)}';

    protected $description = 'Check that the OpenAI Apps domain-verification token is served (never prints it)';

    public const PATH = '/.well-known/openai-apps-challenge';

    public function handle(): int
    {
        $url = (string) ($this->option('url') ?: $this->defaultUrl());
        $local = trim((string) config('mcp.openai_apps_challenge', ''));

        $this->line('Checking '.$url);
        $this->line('OPENAI_APPS_CHALLENGE in this environment: '.($local !== '' ? 'set' : 'not set'));

        try {
            $response = Http::timeout(10)->withOptions(['allow_redirects' => false])->get($url);
        } catch (Throwable) {
            $this->error('Could not reach the URL.');

            return self::FAILURE;
        }

        if ($response->status() === 404) {
            $this->error('Not served (404): OPENAI_APPS_CHALLENGE is not set on the MCP server, or it was not restarted after setting it.');

            return self::FAILURE;
        }
        if ($response->status() !== 200) {
            $this->error('Unexpected HTTP '.$response->status().' (expected 200, no redirect).');

            return self::FAILURE;
        }

        $type = (string) $response->header('Content-Type');
        if (! str_starts_with(strtolower($type), 'text/plain')) {
            $this->error('Served with Content-Type "'.$type.'" (expected text/plain).');

            return self::FAILURE;
        }

        $served = trim($response->body());
        if ($served === '') {
            $this->error('Served, but empty.');

            return self::FAILURE;
        }

        if ($local !== '' && ! hash_equals(hash('sha256', $local), hash('sha256', $served))) {
            $this->error('Served, but it does not match OPENAI_APPS_CHALLENGE in this environment.');

            return self::FAILURE;
        }

        $this->info('OK: served as text/plain'.($local !== '' ? ' and matches OPENAI_APPS_CHALLENGE here' : '').'.');

        return self::SUCCESS;
    }

    private function defaultUrl(): string
    {
        $resource = (string) config('mcp.resource_url');
        $scheme = parse_url($resource, PHP_URL_SCHEME) ?: 'https';
        $host = (string) parse_url($resource, PHP_URL_HOST);
        $port = parse_url($resource, PHP_URL_PORT);

        return $scheme.'://'.$host.($port ? ':'.$port : '').self::PATH;
    }
}
