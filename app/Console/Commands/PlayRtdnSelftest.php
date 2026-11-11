<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\User;
use App\Services\GooglePlayClient;
use App\Support\GoogleIdToken;
use App\Support\GooglePlayBilling;
use Firebase\JWT\JWT;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Local / test only: one Google Play real-time developer notification,
 * end to end through our own code, with Google's side simulated.
 *
 * It signs a Pub/Sub-style OIDC token with a throwaway key (served as
 * Google's JWKS, faked), posts a Pub/Sub push body to POST
 * /api/v1/webhooks/google-play in-process, and answers the
 * androidpublisher read with a subscription in --state. Everything else —
 * token verification (audience, service account email), dedupe, package
 * check, the state mapping, the database write — is the real code.
 *
 * With --user it first gives that user a Play purchase for --token (as the
 * app's verify call would), so the notification has a row to update, and
 * prints the row after. It writes to the local database. Never runs in
 * production; it needs no secrets and talks to no Google endpoint.
 */
class PlayRtdnSelftest extends Command
{
    protected $signature = 'play:rtdn-selftest
        {--type=test : test | purchased | renewed | canceled | recovered | on_hold | in_grace | paused | revoked | expired | voided}
        {--state=ACTIVE : what Google answers: ACTIVE, CANCELED, IN_GRACE_PERIOD, ON_HOLD, PAUSED, EXPIRED}
        {--user= : ulid or email of a local user to give the purchase to}
        {--product=pro_monthly : Play product id}
        {--token= : purchase token (default: a new random one)}';

    protected $description = 'Post a signed fake Pub/Sub RTDN to our own Google Play webhook (local/test only) and print the mapped state';

    /** subscriptionNotification.notificationType by name (Play's numbering). */
    private const TYPES = [
        'recovered' => 1, 'renewed' => 2, 'canceled' => 3, 'purchased' => 4, 'on_hold' => 5,
        'in_grace' => 6, 'paused' => 10, 'revoked' => 12, 'expired' => 13,
    ];

    /** GoogleIdToken's JWKS cache key: the fake keys must not outlive the run. */
    private const JWKS_CACHE_KEY = 'google-id-token:jwks';

    private const AUDIENCE = 'https://api.creatif.test/api/v1/webhooks/google-play';

    private const PUSH_SA = 'play-rtdn-selftest@creatif.iam.gserviceaccount.com';

    public function handle(Kernel $kernel): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('play:rtdn-selftest runs only in local or testing (APP_ENV='.app()->environment().').');

            return self::FAILURE;
        }

        $type = (string) $this->option('type');
        if ($type !== 'test' && $type !== 'voided' && ! isset(self::TYPES[$type])) {
            $this->error("Unknown --type {$type}.");

            return self::FAILURE;
        }

        $token = (string) ($this->option('token') ?: 'selftest-'.Str::lower((string) Str::ulid()));
        $product = (string) $this->option('product');
        $user = $this->user();
        if ($this->option('user') && $user === null) {
            $this->error('No local user '.$this->option('user').'.');

            return self::FAILURE;
        }

        // The audience / service account the push is checked against: this
        // install's own when set, so a mismatch in .env shows up here.
        $audience = (string) (config('services.google_play.rtdn_audience') ?: self::AUDIENCE);
        $pushSa = (string) (config('services.google_play.rtdn_service_account_email') ?: self::PUSH_SA);
        config([
            'services.google_play.rtdn_audience' => $audience,
            'services.google_play.rtdn_service_account_email' => $pushSa,
        ]);

        $push = self::rsaKey();
        $saKey = self::rsaKey();
        $saPath = tempnam(sys_get_temp_dir(), 'rtdn-selftest-sa-');
        file_put_contents($saPath, json_encode([
            'type' => 'service_account', 'client_email' => 'selftest@creatif.iam.gserviceaccount.com',
            'private_key' => $saKey['private'], 'token_uri' => GooglePlayClient::TOKEN_URL,
        ]));
        config(['services.google_play.service_account_json_path' => $saPath]);

        $remote = fn (string $state) => [
            'kind' => 'androidpublisher#subscriptionPurchaseV2',
            'startTime' => now()->subMinute()->toIso8601ZuluString(),
            'subscriptionState' => 'SUBSCRIPTION_STATE_'.$state,
            'latestOrderId' => 'GPA.0000-SELF-TEST-0000',
            'acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED',
            'externalAccountIdentifiers' => ['obfuscatedExternalAccountId' => $user?->ulid],
            'lineItems' => [[
                'productId' => $product,
                'expiryTime' => ($state === 'EXPIRED' ? now()->subDay() : now()->addMonth())->toIso8601ZuluString(),
                'autoRenewingPlan' => ['autoRenewEnabled' => $state === 'ACTIVE'],
            ]],
        ];
        $state = strtoupper((string) $this->option('state'));

        Cache::forget(self::JWKS_CACHE_KEY);
        Http::fake([
            GoogleIdToken::JWKS_URL => Http::response(['keys' => [[
                'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'selftest', 'n' => $push['n'], 'e' => $push['e'],
            ]]], 200, ['Cache-Control' => 'max-age=60']),
            GooglePlayClient::TOKEN_URL => Http::response(['access_token' => 'selftest', 'expires_in' => 3600, 'token_type' => 'Bearer']),
            'androidpublisher.googleapis.com/*' => fn (HttpRequest $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH) ?: '', ':acknowledge')
                ? Http::response('', 204)
                : Http::response($remote($state)),
        ]);

        try {
            if ($user !== null) {
                $this->seed($user, $token, $remote('ACTIVE'));
            }

            $now = now()->getTimestamp();
            $jwt = JWT::encode([
                'iss' => 'https://accounts.google.com', 'aud' => $audience, 'email' => $pushSa, 'email_verified' => true,
                'sub' => 'selftest', 'iat' => $now, 'exp' => $now + 600,
            ], $push['private'], 'RS256', 'selftest');

            $data = ['version' => '1.0', 'packageName' => config('services.google_play.package_name'), 'eventTimeMillis' => (string) ($now * 1000)]
                + match ($type) {
                    'test' => ['testNotification' => ['version' => '1.0']],
                    'voided' => ['voidedPurchaseNotification' => ['purchaseToken' => $token, 'orderId' => 'GPA.0000-SELF-TEST-0000', 'productType' => 1]],
                    default => ['subscriptionNotification' => [
                        'version' => '1.0', 'notificationType' => self::TYPES[$type], 'purchaseToken' => $token, 'subscriptionId' => $product,
                    ]],
                };

            $request = Request::create('/api/v1/webhooks/google-play', 'POST', server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$jwt, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            ], content: (string) json_encode([
                'message' => ['data' => base64_encode((string) json_encode($data)), 'messageId' => 'selftest-'.Str::random(12), 'publishTime' => now()->toIso8601ZuluString()],
                'subscription' => 'projects/selftest/subscriptions/play-rtdn',
            ]));
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);
        } finally {
            Cache::forget(self::JWKS_CACHE_KEY);
            @unlink($saPath);
        }

        $body = json_decode((string) $response->getContent(), true);
        $this->line("Webhook: HTTP {$response->getStatusCode()}, outcome: ".($body['outcome'] ?? '—'));

        if ($user !== null) {
            $row = Subscription::query()->where('google_purchase_token_hash', GooglePlayBilling::hash($token))->with('plan')->first();
            $this->table(['token', 'plan', 'status', 'period ends', 'grace ends', 'auto-renewing'], [[
                Str::limit($token, 24), $row?->plan?->key->value ?? '—', $row?->status->value ?? '—',
                $row?->current_period_end?->toIso8601String() ?? '—', $row?->grace_ends_at?->toIso8601String() ?? '—',
                $row === null ? '—' : ($row->google_auto_renewing ? 'yes' : 'no'),
            ]]);
            $this->line("User's plan now: ".$user->fresh()?->plan()->key->value);
        }

        return $response->getStatusCode() === 200 ? self::SUCCESS : self::FAILURE;
    }

    private function user(): ?User
    {
        $who = (string) $this->option('user');

        return $who === '' ? null : User::query()->where('ulid', $who)->orWhere('email', $who)->first();
    }

    /**
     * The row the app's verify call would have made, through the same
     * mapping (GooglePlayBilling::apply), unless this token already has one.
     *
     * @param  array<string, mixed>  $remote
     */
    private function seed(User $user, string $token, array $remote): void
    {
        $existing = Subscription::query()->where('google_purchase_token_hash', GooglePlayBilling::hash($token))->first();
        if ($existing !== null) {
            return;
        }

        $lineItem = GooglePlayBilling::lineItem($remote);
        $target = $lineItem === null ? null : GooglePlayBilling::target('ACTIVE', $lineItem, null);
        if ($lineItem === null || $target === null) {
            $this->warn('Product '.$this->option('product').' is not a plan product; nothing seeded.');

            return;
        }

        GooglePlayBilling::apply($user, null, $token, $remote, $lineItem, $target);
        $this->line('Seeded an active Play purchase for '.$user->ulid.'.');
    }

    /** @return array{private: string, n: string, e: string} */
    private static function rsaKey(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $details = openssl_pkey_get_details($key);

        return [
            'private' => $private,
            'n' => JWT::urlsafeB64Encode($details['rsa']['n']),
            'e' => JWT::urlsafeB64Encode($details['rsa']['e']),
        ];
    }
}
