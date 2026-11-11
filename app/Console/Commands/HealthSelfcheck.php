<?php

namespace App\Console\Commands;

use App\Support\HealthProbe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The same checks as GET /api/v1/health, from the scheduler (every five
 * minutes): a failing one is logged as a warning naming the checks that
 * failed, so it reaches whatever watches the logs even when nothing calls
 * the endpoint. Exit code 1 on failure.
 */
class HealthSelfcheck extends Command
{
    protected $signature = 'health:selfcheck';

    protected $description = 'Check the database, queue and storage; log a warning when one fails';

    public function handle(HealthProbe $probe): int
    {
        $checks = $probe->run();
        $failed = array_keys(array_filter($checks, fn (bool $pass) => ! $pass));

        foreach ($checks as $name => $pass) {
            $this->line(sprintf('%-8s %s', $name, $pass ? 'ok' : 'FAIL'));
        }

        if ($failed !== []) {
            Log::warning('health.selfcheck_failed', ['failed' => $failed, 'version' => $probe->version()]);
            $this->error('Health check failed: '.implode(', ', $failed));

            return self::FAILURE;
        }

        $this->info('All checks passed.');

        return self::SUCCESS;
    }
}
