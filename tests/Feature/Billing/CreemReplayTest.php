<?php

use App\Billing\CreemReplay;
use App\Console\Commands\BillingReplayWebhooks;
use App\Services\CreemService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/*
 * The Creem TEST-mode webhook replay (CEO-approved): every scenario QA
 * replays on staging before a billing deploy, run here in-process with no
 * network. The staging runner refuses anything that is not staging with
 * Creem in test mode.
 */

beforeEach(function () {
    Notification::fake();
    // Nothing leaves the test: the replay stubs Creem itself.
    Http::preventStrayRequests();
    config(['services.creem.products.pro_monthly_notrial' => 'prod_pro_monthly_notrial']);
});

function replayResult(string $scenario, ?Closure $deliver = null): array
{
    return (new CreemReplay('t'.substr(md5($scenario.microtime()), 0, 8), $deliver))->run($scenario);
}

function failedChecks(array $result): string
{
    return collect($result['checks'])->reject(fn ($c) => $c['ok'])
        ->map(fn ($c) => $c['check'].($c['detail'] !== '' ? ': '.$c['detail'] : ''))->implode('; ');
}

it('passes every scenario against this API', function (string $scenario) {
    $result = replayResult($scenario);

    expect($result['result'])->toBe('passed', failedChecks($result))
        ->and($result['checks'])->not->toBeEmpty();
})->with(array_keys(CreemReplay::SCENARIOS));

it('covers the scenarios QA asked for', function () {
    expect(array_keys(CreemReplay::SCENARIOS))->toBe([
        'cancel_scheduled',
        'change_plan_on_cancel_scheduled',
        'refund',
        'credits_topup',
        'change_plan_active',
        'checkout_trial',
        'checkout_notrial',
    ]);
});

it('fails a scenario when the API does not accept the webhook', function () {
    $result = replayResult('credits_topup', fn (string $body, string $signature) => 500);

    expect($result['result'])->toBe('failed')
        ->and(failedChecks($result))->toContain('top-up accepted: HTTP 500');
});

it('signs with the webhook secret from config, so a wrong secret is refused (403)', function () {
    $sent = [];
    $result = replayResult('refund', function (string $body, string $signature) use (&$sent) {
        $sent[] = $signature;
        config(['services.creem.webhook_secret' => 'not-the-real-one']);

        return app(CreemService::class)->verifySignature($body, $signature) ? 200 : 403;
    });

    expect($sent)->not->toBeEmpty()
        ->and($sent[0])->toMatch('/^[0-9a-f]{64}$/')
        ->and($result['result'])->toBe('failed');
});

it('skips a scenario whose product is not configured, instead of failing it', function () {
    config(['services.creem.products.pro_monthly_notrial' => null]);

    expect(replayResult('checkout_notrial'))->toMatchArray([
        'result' => 'skipped',
        'note' => 'CREEM_PRODUCT_PRO_MONTHLY_NOTRIAL is not set',
    ]);
});

describe('the runner refuses anything but staging in Creem test mode', function () {
    afterEach(fn () => app()->instance('env', 'testing'));

    it('refuses production, test mode off, or no secret', function () {
        app()->instance('env', 'production');
        expect(BillingReplayWebhooks::refusal('staging'))->toBe('APP_ENV is production.')
            ->and(BillingReplayWebhooks::refusal('local'))->toBe('APP_ENV is production.');

        app()->instance('env', 'staging');
        config(['app.url' => 'https://staging-api.creatif.space', 'services.creem.test_mode' => false]);
        expect(BillingReplayWebhooks::refusal('staging'))->toBe('CREEM_TEST_MODE is not true.');

        config(['services.creem.test_mode' => true, 'services.creem.webhook_secret' => '']);
        expect(BillingReplayWebhooks::refusal('staging'))->toBe('CREEM_WEBHOOK_SECRET is empty.');
    });

    it('runs against staging only with APP_ENV staging and a staging host', function () {
        config(['app.url' => 'https://staging-api.creatif.space']);
        expect(BillingReplayWebhooks::refusal('staging'))->toBe('APP_ENV is not staging.');

        app()->instance('env', 'staging');
        expect(BillingReplayWebhooks::refusal('staging'))->toBeNull();

        foreach (['https://api.creatif.space', 'https://app.creatif.space', 'https://notstaging-api.creatif.space', 'https://api.staging.creatif.space'] as $url) {
            config(['app.url' => $url]);
            expect(BillingReplayWebhooks::refusal('staging'))->toContain('is not a staging host');
        }
        foreach (['https://staging.creatif.space', 'https://staging-api.creatif.space'] as $url) {
            config(['app.url' => $url]);
            expect(BillingReplayWebhooks::refusal('staging'))->toBeNull($url);
        }

        expect(BillingReplayWebhooks::refusal('local'))->toBe('--env-target=local runs only with APP_ENV local or testing.')
            ->and(BillingReplayWebhooks::refusal('prod'))->toContain('--env-target must be staging or local');
    });

    it('says so and sends nothing when refused', function () {
        config(['app.url' => 'https://api.creatif.space']);

        $this->artisan('billing:replay-webhooks', ['--env-target' => 'staging'])
            ->expectsOutputToContain('Refusing to run: APP_ENV is not staging.')
            ->assertFailed();

        Http::assertNothingSent();
    });
});

it('runs every scenario in-process from the command (local), and reports each', function () {
    $command = $this->artisan('billing:replay-webhooks', ['--env-target' => 'local']);
    foreach (array_keys(CreemReplay::SCENARIOS) as $name) {
        $command->expectsOutputToContain("PASS {$name}");
    }
    $command->expectsOutputToContain('All scenarios passed.')->assertSuccessful();
});

it('runs only the scenarios asked for, and refuses an unknown one', function () {
    $this->artisan('billing:replay-webhooks', ['--env-target' => 'local', '--only' => ['refund']])
        ->expectsOutputToContain('PASS refund')
        ->doesntExpectOutputToContain('credits_topup')
        ->assertSuccessful();

    $this->artisan('billing:replay-webhooks', ['--env-target' => 'local', '--only' => ['nope']])
        ->expectsOutputToContain('Unknown scenario(s): nope')
        ->assertFailed();
});

it('keeps the fixtures free of secrets and real ids: every id and product is a placeholder', function () {
    $files = glob(base_path('resources/billing/creem-replay/*.json'));
    expect($files)->toHaveCount(5);

    foreach ($files as $file) {
        $text = file_get_contents($file);
        $json = json_decode($text, true, 512, JSON_THROW_ON_ERROR);

        expect($json['id'])->toBe('{{event_id}}', basename($file))
            ->and($text)->not->toMatch('/creem_(test|live)_|whsec_|prod_[A-Za-z0-9]{6,}|sub_[A-Za-z0-9]{6,}/', basename($file));
        if (! str_starts_with(basename($file), 'refund.')) {
            expect($text)->toContain('"product": "{{product}}"');
        }
    }
});
