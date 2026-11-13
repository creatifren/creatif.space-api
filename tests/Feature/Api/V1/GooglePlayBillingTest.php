<?php

use App\Jobs\ExpireSubscriptions;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Analytics;
use App\Services\CreemService;
use App\Services\GooglePlayClient;
use App\Support\GoogleIdToken;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * Google Play Billing: POST /billing/google/verify (the Android app) and
 * POST /webhooks/google-play (real-time developer notifications via
 * Pub/Sub push). Google's OAuth token endpoint, the Android Publisher API
 * and its JWKS are faked; every key is generated for the test.
 */

const GP_AUDIENCE = 'https://api.creatif.test/api/v1/webhooks/google-play';
const GP_PUSH_SA = 'rtdn-push@example-project.iam.gserviceaccount.com';

beforeEach(function () {
    Cache::flush();
    $this->analytics = Analytics::fake();

    // The service account key file, as it would sit on the server.
    $this->gpSaKey = gpRsaKey();
    $this->gpSaPath = tempnam(sys_get_temp_dir(), 'gp-sa-');
    file_put_contents($this->gpSaPath, json_encode([
        'type' => 'service_account',
        'client_email' => 'play-api@example-project.iam.gserviceaccount.com',
        'private_key' => $this->gpSaKey['private'],
        'token_uri' => 'https://oauth2.googleapis.com/token',
    ]));

    config([
        'services.google_play.package_name' => 'space.creatif.app',
        'services.google_play.service_account_json_path' => $this->gpSaPath,
        'services.google_play.rtdn_audience' => GP_AUDIENCE,
        'services.google_play.rtdn_service_account_email' => GP_PUSH_SA,
    ]);

    // Google's side: purchase token => subscriptionsv2 body.
    $this->gp = new stdClass;
    $this->gp->remote = [];
    $this->gp->acks = [];
    $this->gp->tokenCalls = 0;
    $this->gp->down = false;
    $this->gpPushKey = gpRsaKey();

    Http::preventStrayRequests();
    Http::fake([
        GooglePlayClient::TOKEN_URL => function (HttpRequest $request) {
            $this->gp->tokenCalls++;
            expect($request['grant_type'])->toBe('urn:ietf:params:oauth:grant-type:jwt-bearer');
            // The assertion is signed with the service account's key.
            $claims = JWT::decode($request['assertion'], new Key($this->gpSaKey['public'], 'RS256'));
            expect($claims->scope)->toBe(GooglePlayClient::SCOPE)
                ->and($claims->iss)->toBe('play-api@example-project.iam.gserviceaccount.com');

            return Http::response(['access_token' => 'ya29.test-access', 'expires_in' => 3599, 'token_type' => 'Bearer']);
        },
        'androidpublisher.googleapis.com/*' => function (HttpRequest $request) {
            if ($this->gp->down) {
                return Http::response(['error' => ['code' => 503]], 503);
            }
            expect($request->header('Authorization')[0] ?? null)->toBe('Bearer ya29.test-access');
            $path = parse_url($request->url(), PHP_URL_PATH);
            expect($path)->toStartWith('/androidpublisher/v3/applications/space.creatif.app/');

            if (preg_match('#/purchases/subscriptions/([^/]+)/tokens/([^/]+):acknowledge$#', $path, $m)) {
                $token = rawurldecode($m[2]);
                $this->gp->acks[] = [rawurldecode($m[1]), $token];
                $this->gp->remote[$token]['acknowledgementState'] = 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED';

                return Http::response('', 204);
            }
            if (preg_match('#/purchases/subscriptionsv2/tokens/([^/]+)$#', $path, $m)) {
                $token = rawurldecode($m[1]);

                return isset($this->gp->remote[$token])
                    ? Http::response($this->gp->remote[$token])
                    : Http::response(['error' => ['code' => 404, 'message' => 'not found']], 404);
            }

            return Http::response([], 500);
        },
        GoogleIdToken::JWKS_URL => fn () => Http::response(['keys' => [gpJwk($this->gpPushKey, 'push-kid')]], 200, ['Cache-Control' => 'max-age=3600']),
    ]);
});

afterEach(function () {
    @unlink($this->gpSaPath);
});

/** @return array{private: string, public: string, n: string, e: string} */
function gpRsaKey(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private);
    $details = openssl_pkey_get_details($key);

    return [
        'private' => $private,
        'public' => $details['key'],
        'n' => JWT::urlsafeB64Encode($details['rsa']['n']),
        'e' => JWT::urlsafeB64Encode($details['rsa']['e']),
    ];
}

function gpJwk(array $key, string $kid): array
{
    return ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $kid, 'n' => $key['n'], 'e' => $key['e']];
}

/** Put a subscription on Google's side. */
function gpRemote(string $token, User $user, string $state = 'ACTIVE', string $product = 'pro_monthly', array $extra = []): void
{
    test()->gp->remote[$token] = array_replace_recursive([
        'kind' => 'androidpublisher#subscriptionPurchaseV2',
        'regionCode' => 'ID',
        'startTime' => now()->subMinute()->toIso8601ZuluString(),
        'subscriptionState' => 'SUBSCRIPTION_STATE_'.$state,
        'latestOrderId' => 'GPA.1234-5678-9012-34567',
        'acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_PENDING',
        'externalAccountIdentifiers' => ['obfuscatedExternalAccountId' => $user->ulid],
        'lineItems' => [[
            'productId' => $product,
            'expiryTime' => now()->addMonth()->toIso8601ZuluString(),
            'autoRenewingPlan' => ['autoRenewEnabled' => $state === 'ACTIVE'],
        ]],
    ], $extra);
}

function gpVerify(User $user, string $token, string $product = 'pro_monthly')
{
    return test()->actingAs($user)->postJson('/api/v1/billing/google/verify', [
        'purchase_token' => $token,
        'product_id' => $product,
    ]);
}

/** Pub/Sub's OIDC token for the push, $claims overriding. */
function gpPushJwt(array $claims = []): string
{
    $now = now()->getTimestamp();

    return JWT::encode([
        'iss' => 'https://accounts.google.com',
        'aud' => GP_AUDIENCE,
        'azp' => '1234567890',
        'sub' => '1234567890',
        'email' => GP_PUSH_SA,
        'email_verified' => true,
        'iat' => $now,
        'exp' => $now + 3600,
        ...$claims,
    ], test()->gpPushKey['private'], 'RS256', 'push-kid');
}

/** Deliver an RTDN as Pub/Sub pushes it. */
function gpPush(array $data, ?string $jwt = null, ?string $messageId = null)
{
    return test()->withHeader('Authorization', 'Bearer '.($jwt ?? gpPushJwt()))
        ->postJson('/api/v1/webhooks/google-play', [
            'message' => [
                'data' => base64_encode(json_encode(['version' => '1.0', 'packageName' => 'space.creatif.app', 'eventTimeMillis' => (string) (now()->getTimestamp() * 1000), ...$data])),
                'messageId' => $messageId ?? (string) random_int(1, PHP_INT_MAX),
                'publishTime' => now()->toIso8601ZuluString(),
            ],
            'subscription' => 'projects/creatif-play/subscriptions/play-rtdn',
        ]);
}

function gpNotify(string $token, int $type, string $product = 'pro_monthly', ?string $messageId = null)
{
    return gpPush(['subscriptionNotification' => [
        'version' => '1.0', 'notificationType' => $type, 'purchaseToken' => $token, 'subscriptionId' => $product,
    ]], messageId: $messageId);
}

describe('verify', function () {
    it('grants Pro monthly from an active Play purchase and acknowledges it', function () {
        $user = User::factory()->create();
        gpRemote('tok-pro', $user);

        gpVerify($user, 'tok-pro')->assertOk()->assertJson([
            'plan' => 'pro', 'period' => 'monthly', 'status' => 'active', 'provider' => 'google',
        ])->assertJsonPath('current_period_end', fn ($v) => is_string($v));

        $sub = Subscription::query()->sole();
        expect($sub->provider)->toBe('google')
            ->and($sub->google_purchase_token_hash)->toBe(hash('sha256', 'tok-pro'))
            ->and($sub->google_product_id)->toBe('pro_monthly')
            ->and($sub->google_order_id)->toBe('GPA.1234-5678-9012-34567')
            ->and($sub->google_auto_renewing)->toBeTrue()
            ->and($user->fresh()->plan()->key->value)->toBe('pro');
        expect($this->gp->acks)->toBe([['pro_monthly', 'tok-pro']]);

        $this->analytics->assertCapturedOnce('subscription_started', fn ($p) => $p['provider'] === 'google' && $p['plan'] === 'pro' && $p['period'] === 'monthly');
    });

    it('grants Max yearly', function () {
        $user = User::factory()->create();
        gpRemote('tok-max', $user, product: 'max_yearly', extra: ['lineItems' => [['expiryTime' => now()->addYear()->toIso8601ZuluString()]]]);

        gpVerify($user, 'tok-max', 'max_yearly')->assertOk()->assertJson(['plan' => 'max', 'period' => 'yearly', 'status' => 'active']);
        expect($user->fresh()->plan()->key->value)->toBe('max');
    });

    it('is idempotent: the same token twice is the same row, acknowledged once', function () {
        $user = User::factory()->create();
        gpRemote('tok-replay', $user);

        gpVerify($user, 'tok-replay')->assertOk();
        gpVerify($user, 'tok-replay')->assertOk()->assertJson(['plan' => 'pro', 'status' => 'active']);

        expect(Subscription::query()->count())->toBe(1)
            ->and($this->gp->acks)->toHaveCount(1);
        $this->analytics->assertCapturedOnce('subscription_started');
        // The access token was cached between the two calls.
        expect($this->gp->tokenCalls)->toBe(1);
    });

    it('refuses a purchase made for another account', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        gpRemote('tok-other', $other);

        gpVerify($user, 'tok-other')->assertForbidden()->assertJson(['reason' => 'account_mismatch']);
        expect(Subscription::query()->count())->toBe(0)->and($this->gp->acks)->toBe([]);
    });

    it('refuses a product that is not a plan, before asking Google', function () {
        $user = User::factory()->create();

        gpVerify($user, 'tok-x', 'coins_100')->assertStatus(422)->assertJson(['reason' => 'unknown_product']);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'androidpublisher'));
    });

    it('refuses when the purchase is for a different product than claimed', function () {
        $user = User::factory()->create();
        gpRemote('tok-pro', $user);

        gpVerify($user, 'tok-pro', 'max_yearly')->assertStatus(422)->assertJson(['reason' => 'unknown_product']);
    });

    it('answers invalid_token when Google does not know the token', function () {
        $user = User::factory()->create();

        gpVerify($user, 'tok-missing')->assertStatus(422)->assertJson(['reason' => 'invalid_token']);
    });

    it('grants nothing while the payment is pending', function () {
        $user = User::factory()->create();
        gpRemote('tok-pending', $user, 'PENDING');

        gpVerify($user, 'tok-pending')->assertStatus(202)->assertJson(['reason' => 'pending']);
        expect(Subscription::query()->count())->toBe(0)
            ->and($user->fresh()->plan()->key->value)->toBe('free')
            ->and($this->gp->acks)->toBe([]);
    });

    it('refuses a Play plan while a web (Creem) plan runs, and leaves it unacknowledged', function () {
        $user = User::factory()->create();
        Subscription::factory()->for($user)->create(['creem_subscription_id' => 'sub_web']);
        gpRemote('tok-dupe', $user);

        gpVerify($user, 'tok-dupe')->assertStatus(409)->assertJson([
            'reason' => 'active_web_subscription',
            'message' => 'You already have a plan from the web. Manage it at creatif.space.',
        ]);
        expect(Subscription::query()->where('provider', 'google')->count())->toBe(0)
            ->and($this->gp->acks)->toBe([]);
    });

    it('refuses a Creem checkout while a Play plan runs', function () {
        $user = User::factory()->create();
        gpRemote('tok-play', $user);
        gpVerify($user, 'tok-play')->assertOk();

        $this->actingAs($user)->postJson('/api/v1/me/subscription/checkout', ['plan' => 'max', 'period' => 'monthly'])
            ->assertStatus(409)->assertJson(['reason' => 'active_play_subscription']);
        $this->actingAs($user)->postJson('/api/v1/me/subscription/cancel')
            ->assertStatus(409)->assertJson(['reason' => 'active_play_subscription']);
    });

    it('supersedes the old purchase on an upgrade (linkedPurchaseToken)', function () {
        $user = User::factory()->create();
        gpRemote('tok-old', $user);
        gpVerify($user, 'tok-old')->assertOk();

        gpRemote('tok-new', $user, product: 'max_monthly', extra: ['linkedPurchaseToken' => 'tok-old']);
        gpVerify($user, 'tok-new', 'max_monthly')->assertOk()->assertJson(['plan' => 'max']);

        $old = Subscription::query()->where('google_purchase_token_hash', hash('sha256', 'tok-old'))->sole();
        $new = Subscription::query()->where('google_purchase_token_hash', hash('sha256', 'tok-new'))->sole();
        expect($old->status->value)->toBe('expired')
            ->and($new->status->value)->toBe('active')
            ->and($new->google_linked_purchase_token_hash)->toBe(hash('sha256', 'tok-old'))
            ->and(Subscription::query()->entitled()->count())->toBe(1)
            ->and($user->fresh()->plan()->key->value)->toBe('max');
        // A change of plan, not a second subscription start.
        $this->analytics->assertCapturedOnce('subscription_started');

        // A late notification about the replaced token does not revive it.
        gpNotify('tok-old', 2)->assertOk();
        expect($old->fresh()->status->value)->toBe('expired');
    });

    it('keeps a cancelled purchase entitled until it expires', function () {
        $user = User::factory()->create();
        gpRemote('tok-cancel', $user, 'CANCELED');

        gpVerify($user, 'tok-cancel')->assertOk()->assertJson(['status' => 'cancelled']);
        expect($user->fresh()->plan()->key->value)->toBe('pro');
    });

    it('answers 503, not 500, when the service account file is missing', function () {
        $user = User::factory()->create();
        Log::spy();
        config(['services.google_play.service_account_json_path' => '/nonexistent/play-sa.json']);

        gpVerify($user, 'tok-any')->assertStatus(503)->assertJson(['reason' => 'google_unavailable']);
    });

    it('answers 503 when Google is down', function () {
        $user = User::factory()->create();
        $this->gp->down = true;

        gpVerify($user, 'tok-any')->assertStatus(503)->assertJson(['reason' => 'google_unavailable']);
    });

    it('never writes a purchase token to the log', function () {
        $user = User::factory()->create();
        $logged = [];
        Log::listen(function ($event) use (&$logged) {
            $logged[] = $event->message.json_encode($event->context);
        });
        config(['services.google_play.service_account_json_path' => '/nonexistent/play-sa.json']);
        gpVerify($user, 'tok-secret-123')->assertStatus(503);

        expect(implode("\n", $logged))->not->toContain('tok-secret-123')->not->toContain('/nonexistent');
    });

    it('shows the provider on /me and the subscription screen', function () {
        $user = User::factory()->create();
        gpRemote('tok-me', $user);
        gpVerify($user, 'tok-me')->assertOk();

        $user = $user->fresh();
        $this->actingAs($user)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.billing_provider', 'google');
        $this->actingAs($user)->getJson('/api/v1/me/subscription')->assertOk()
            ->assertJsonPath('data.plan.provider', 'google')
            ->assertJsonPath('data.subscription.provider', 'google')
            ->assertJsonPath('data.subscription.manage_url', fn ($u) => str_starts_with($u, 'https://play.google.com/store/account/subscriptions?'))
            ->assertJsonMissingPath('data.subscription.google_purchase_token');
    });
});

describe('real-time developer notifications', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        gpRemote('tok-rtdn', $this->user);
        gpVerify($this->user, 'tok-rtdn')->assertOk();
        $this->sub = Subscription::query()->sole();
    });

    it('follows Google through each state', function (string $state, int $type, string $status, bool $entitled) {
        gpRemote('tok-rtdn', $this->user, $state, extra: ['acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED']);

        gpNotify('tok-rtdn', $type)->assertOk();

        $sub = $this->sub->fresh();
        expect($sub->status->value)->toBe($status)
            ->and($sub->isEntitled())->toBe($entitled)
            ->and($this->user->fresh()->plan()->key->value)->toBe($entitled ? 'pro' : 'free');
    })->with([
        'RENEWED' => ['ACTIVE', 2, 'active', true],
        'IN_GRACE_PERIOD' => ['IN_GRACE_PERIOD', 6, 'active', true],
        'ON_HOLD' => ['ON_HOLD', 5, 'past_due', true],
        'PAUSED' => ['PAUSED', 10, 'past_due', true],
        'CANCELED' => ['CANCELED', 3, 'cancelled', true],
        'EXPIRED' => ['EXPIRED', 13, 'expired', false],
    ]);

    it('sets the grace end from expiryTime while in grace', function () {
        $graceEnd = now()->addDays(3)->startOfSecond();
        gpRemote('tok-rtdn', $this->user, 'IN_GRACE_PERIOD', extra: ['lineItems' => [['expiryTime' => $graceEnd->toIso8601ZuluString()]]]);

        gpNotify('tok-rtdn', 6)->assertOk();
        expect($this->sub->fresh()->grace_ends_at->getTimestamp())->toBe($graceEnd->getTimestamp());
    });

    it('moves the period forward on a renewal and recovers from hold', function () {
        gpRemote('tok-rtdn', $this->user, 'ON_HOLD');
        gpNotify('tok-rtdn', 5)->assertOk();
        expect($this->sub->fresh()->status->value)->toBe('past_due');

        $next = now()->addMonths(2)->startOfSecond();
        gpRemote('tok-rtdn', $this->user, 'ACTIVE', extra: ['lineItems' => [['expiryTime' => $next->toIso8601ZuluString()]]]);
        gpNotify('tok-rtdn', 1)->assertOk();

        $sub = $this->sub->fresh();
        expect($sub->status->value)->toBe('active')
            ->and($sub->grace_ends_at)->toBeNull()
            ->and($sub->current_period_end->getTimestamp())->toBe($next->getTimestamp());
    });

    it('ends the plan at once on REVOKED (refund), whatever the period says', function () {
        gpRemote('tok-rtdn', $this->user, 'ACTIVE');

        gpNotify('tok-rtdn', 12)->assertOk();
        expect($this->sub->fresh()->status->value)->toBe('expired')
            ->and($this->user->fresh()->plan()->key->value)->toBe('free');
    });

    it('ends the plan on a voided purchase', function () {
        gpPush(['voidedPurchaseNotification' => ['purchaseToken' => 'tok-rtdn', 'orderId' => 'GPA.1', 'productType' => 1]])->assertOk();
        expect($this->sub->fresh()->status->value)->toBe('expired');
    });

    it('acknowledges a pending purchase it hears about first', function () {
        gpRemote('tok-late', $this->user);
        // Row exists (e.g. an earlier verify whose acknowledge failed).
        Subscription::factory()->for($this->user)->google('tok-late')->create();
        $this->gp->acks = [];

        gpNotify('tok-late', 4)->assertOk();
        expect($this->gp->acks)->toBe([['pro_monthly', 'tok-late']]);
    });

    it('answers a test notification', function () {
        gpPush(['testNotification' => ['version' => '1.0']])->assertOk()->assertJson(['outcome' => 'test']);
    });

    it('stores nothing for an unknown token', function () {
        gpRemote('tok-stranger', $this->user);

        gpNotify('tok-stranger', 4)->assertOk()->assertJson(['outcome' => 'unknown_token']);
        expect(Subscription::query()->count())->toBe(1);
    });

    it('handles a message once (dedupe by messageId)', function () {
        gpRemote('tok-rtdn', $this->user, 'CANCELED');
        gpNotify('tok-rtdn', 3, messageId: 'msg-1')->assertOk()->assertJson(['outcome' => 'applied']);

        gpRemote('tok-rtdn', $this->user, 'ACTIVE');
        gpNotify('tok-rtdn', 3, messageId: 'msg-1')->assertOk()->assertJson(['outcome' => 'duplicate']);
        expect($this->sub->fresh()->status->value)->toBe('cancelled');
    });

    it('stays 2xx when Google cannot be reached', function () {
        $this->gp->down = true;

        gpNotify('tok-rtdn', 2)->assertOk()->assertJson(['outcome' => 'deferred']);
    });

    it('refuses a push without a valid Google OIDC token', function (array $claims) {
        gpPush(['testNotification' => []], gpPushJwt($claims))->assertUnauthorized();
    })->with([
        'wrong aud' => [['aud' => 'https://evil.test/hook']],
        'wrong iss' => [['iss' => 'https://evil.test']],
        'wrong email' => [['email' => 'someone@evil.test']],
        'unverified email' => [['email_verified' => false]],
        'expired' => [['iat' => now()->subHours(3)->getTimestamp(), 'exp' => now()->subHours(2)->getTimestamp()]],
    ]);

    it('refuses a push signed by a key Google did not publish, or not a JWT at all', function () {
        $now = now()->getTimestamp();
        $forged = JWT::encode([
            'iss' => 'https://accounts.google.com', 'aud' => GP_AUDIENCE, 'email' => GP_PUSH_SA,
            'email_verified' => true, 'iat' => $now, 'exp' => $now + 600,
        ], gpRsaKey()['private'], 'RS256', 'push-kid');

        gpPush(['testNotification' => []], $forged)->assertUnauthorized();
        gpPush(['testNotification' => []], 'not-a-jwt')->assertUnauthorized();
    });

    it('refuses a push with no bearer at all', function () {
        $this->postJson('/api/v1/webhooks/google-play', ['message' => ['data' => base64_encode('{}'), 'messageId' => '1']])
            ->assertUnauthorized();
    });
});

describe('expiry job', function () {
    it('expires Play rows like Creem ones: after the period plus grace, cancelled at period end', function () {
        $running = Subscription::factory()->google('tok-a')->create(['current_period_end' => now()->subDays(2)]);
        $lapsed = Subscription::factory()->google('tok-b')->create(['current_period_end' => now()->subDays(Subscription::GRACE_DAYS + 1)]);
        $cancelled = Subscription::factory()->google('tok-c')->create(['status' => 'cancelled', 'cancelled_at' => now()->subDays(10), 'current_period_end' => now()->subMinute()]);
        $cancelledRunning = Subscription::factory()->google('tok-d')->create(['status' => 'cancelled', 'cancelled_at' => now()->subDay(), 'current_period_end' => now()->addDays(5)]);
        $onHold = Subscription::factory()->google('tok-e')->create(['status' => 'past_due', 'grace_ends_at' => now()->subMinute()]);

        (new ExpireSubscriptions)->handle(app(CreemService::class));

        expect($running->fresh()->status->value)->toBe('active')
            ->and($lapsed->fresh()->status->value)->toBe('expired')
            ->and($cancelled->fresh()->status->value)->toBe('expired')
            ->and($cancelledRunning->fresh()->status->value)->toBe('cancelled')
            ->and($cancelledRunning->fresh()->isEntitled())->toBeTrue()
            ->and($onHold->fresh()->status->value)->toBe('expired');
        Http::assertNothingSent();
    });
});
