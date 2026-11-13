<?php

namespace App\Console\Commands;

use App\Billing\CreemReplay;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Replay Creem TEST-mode webhooks against this API and check the accounts
 * they touch (App\Billing\CreemReplay). QA runs it on staging before every
 * billing deploy:
 *
 *   php artisan billing:replay-webhooks --env-target=staging
 *
 * Refuses to run in production, without CREEM_TEST_MODE=true, without a
 * webhook secret, and — for staging — unless APP_ENV is staging and
 * APP_URL's host is a staging host. Each run makes its own throwaway
 * accounts (creem-replay+<run>-…@example.com); nothing is sent to Creem.
 */
class BillingReplayWebhooks extends Command
{
    protected $signature = 'billing:replay-webhooks
        {--env-target=staging : staging (webhooks over HTTP to APP_URL) or local (in-process)}
        {--only=* : run only these scenarios}';

    protected $description = 'Replay Creem TEST-mode webhooks against this API and check the resulting account state';

    public function handle(): int
    {
        $target = (string) $this->option('env-target');
        $refusal = self::refusal($target);
        if ($refusal !== null) {
            $this->error('Refusing to run: '.$refusal);

            return self::FAILURE;
        }

        $only = (array) $this->option('only');
        $unknown = array_diff($only, array_keys(CreemReplay::SCENARIOS));
        if ($unknown !== []) {
            $this->error('Unknown scenario(s): '.implode(', ', $unknown).'. Known: '.implode(', ', array_keys(CreemReplay::SCENARIOS)));

            return self::FAILURE;
        }

        $run = strtolower((string) Str::ulid());
        $replay = new CreemReplay($run, $target === 'staging' ? self::overHttp() : null);
        $this->info("Creem webhook replay {$run} → ".($target === 'staging' ? rtrim((string) config('app.url'), '/') : 'in-process'));

        $failed = 0;
        foreach (CreemReplay::SCENARIOS as $name => $what) {
            if ($only !== [] && ! in_array($name, $only, true)) {
                continue;
            }
            $result = $replay->run($name);
            $mark = ['passed' => '<info>PASS</info>', 'failed' => '<error>FAIL</error>', 'skipped' => '<comment>SKIP</comment>'][$result['result']];
            $this->line("{$mark} {$name} — {$what}".($result['note'] !== null ? " ({$result['note']})" : ''));
            foreach ($result['checks'] as $check) {
                if (! $check['ok']) {
                    $this->line("     ✗ {$check['check']}".($check['detail'] !== '' ? ": {$check['detail']}" : ''));
                }
            }
            $failed += $result['result'] === 'failed' ? 1 : 0;
        }

        if ($failed > 0) {
            $this->error("{$failed} scenario(s) failed. Do not deploy billing changes until they pass.");

            return self::FAILURE;
        }
        $this->info('All scenarios passed.');

        return self::SUCCESS;
    }

    /** Why this run is refused, or null. Never production; staging only on a staging host. */
    public static function refusal(string $target): ?string
    {
        if (! in_array($target, ['staging', 'local'], true)) {
            return "--env-target must be staging or local, not \"{$target}\".";
        }
        if (app()->environment('production')) {
            return 'APP_ENV is production.';
        }
        if (config('services.creem.test_mode') !== true) {
            return 'CREEM_TEST_MODE is not true.';
        }
        if ((string) config('services.creem.webhook_secret') === '') {
            return 'CREEM_WEBHOOK_SECRET is empty.';
        }

        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($target === 'staging') {
            if (! app()->environment('staging')) {
                return 'APP_ENV is not staging.';
            }
            // The first label names the host: staging-api.creatif.space, staging.example.com.
            if (preg_match('/^staging([.-]|$)/', $host) !== 1) {
                return "APP_URL's host ({$host}) is not a staging host.";
            }

            return null;
        }

        if (! app()->environment(['local', 'testing'])) {
            return '--env-target=local runs only with APP_ENV local or testing.';
        }

        return null;
    }

    /** Send each signed event to this API's webhook URL, as Creem would. */
    private static function overHttp(): \Closure
    {
        $url = rtrim((string) config('app.url'), '/').'/api/v1/webhooks/creem';

        return function (string $body, string $signature) use ($url): int {
            try {
                return Http::timeout(20)
                    ->withHeaders(['creem-signature' => $signature, 'Accept' => 'application/json'])
                    ->withBody($body, 'application/json')
                    ->post($url)
                    ->status();
            } catch (Throwable) {
                return 0;
            }
        };
    }
}
