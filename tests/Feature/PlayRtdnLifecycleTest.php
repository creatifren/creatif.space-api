<?php

use App\Exceptions\InvalidGoogleIdToken;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Analytics;
use App\Services\GooglePlayClient;
use App\Support\GooglePlayBilling;
use App\Support\GooglePushToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Chain item 3: one Play subscription through its life by RTDN — purchase,
 * renew, cancel, refund — and a duplicate delivery. Google is faked at the
 * edges (no keys, no credentials): the push verifier accepts a fixed
 * bearer, and the Play Developer API client answers from an in-memory
 * purchase. The webhook, dedupe and GooglePlayBilling mapping are real.
 */

const RTDN_TOKEN = 'play-token-lifecycle';

beforeEach(function () {
    Cache::flush();
    Analytics::fake();
    Http::preventStrayRequests();
    config(['services.google_play.package_name' => 'space.creatif.app']);

    // Fake verifier: only "Bearer good" is Google.
    app()->instance(GooglePushToken::class, new class extends GooglePushToken
    {
        public function __construct() {}

        public function verify(string $jwt): array
        {
            if ($jwt !== 'good') {
                throw new InvalidGoogleIdToken('fake: not Google');
            }

            return ['email' => 'push@test', 'aud' => 'test'];
        }
    });

    // Fake Play Developer API: what Google says about the purchase now.
    $this->remote = null;
    $this->acks = 0;
    $test = $this;
    app()->instance(GooglePlayClient::class, new class($test) extends GooglePlayClient
    {
        public function __construct(private $test) {}

        public function getSubscriptionV2(string $purchaseToken): array
        {
            return $this->test->remote;
        }

        public function acknowledge(string $productId, string $purchaseToken): void
        {
            $this->test->acks++;
        }
    });
});

/** Google's view of the purchase, as subscriptionsv2. */
function rtdnRemote(User $user, string $state, ?string $expiry = null, bool $acked = true): array
{
    return [
        'kind' => 'androidpublisher#subscriptionPurchaseV2',
        'startTime' => now()->subMinute()->toIso8601ZuluString(),
        'subscriptionState' => 'SUBSCRIPTION_STATE_'.$state,
        'latestOrderId' => 'GPA.1111-2222-3333-44444',
        'acknowledgementState' => $acked ? 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED' : 'ACKNOWLEDGEMENT_STATE_PENDING',
        'externalAccountIdentifiers' => ['obfuscatedExternalAccountId' => $user->ulid],
        'lineItems' => [[
            'productId' => 'pro_monthly',
            'expiryTime' => $expiry ?? now()->addMonth()->toIso8601ZuluString(),
            'autoRenewingPlan' => ['autoRenewEnabled' => $state === 'ACTIVE'],
        ]],
    ];
}

/** One Pub/Sub push, as Google delivers it. */
function rtdnPush(int $type, string $messageId, string $bearer = 'good')
{
    return test()->withHeader('Authorization', 'Bearer '.$bearer)->postJson('/api/v1/webhooks/google-play', [
        'message' => [
            'data' => base64_encode(json_encode([
                'version' => '1.0', 'packageName' => 'space.creatif.app', 'eventTimeMillis' => (string) (now()->getTimestamp() * 1000),
                'subscriptionNotification' => ['version' => '1.0', 'notificationType' => $type, 'purchaseToken' => RTDN_TOKEN, 'subscriptionId' => 'pro_monthly'],
            ])),
            'messageId' => $messageId,
        ],
        'subscription' => 'projects/test/subscriptions/play-rtdn',
    ]);
}

function rtdnRow(): ?Subscription
{
    return Subscription::query()->where('google_purchase_token_hash', GooglePlayBilling::hash(RTDN_TOKEN))->first();
}

it('follows a Play subscription from purchase through renewal, cancellation and refund', function () {
    $user = User::factory()->create();

    // Purchase: the app's verify call makes the row (acknowledging it).
    $this->remote = rtdnRemote($user, 'ACTIVE', acked: false);
    $this->actingAs($user)->postJson('/api/v1/billing/google/verify', ['purchase_token' => RTDN_TOKEN, 'product_id' => 'pro_monthly'])->assertSuccessful();
    app('auth')->forgetGuards();
    expect(rtdnRow()->status->value)->toBe('active')
        ->and($user->fresh()->plan()->key->value)->toBe('pro')
        ->and($this->acks)->toBe(1);

    // SUBSCRIPTION_PURCHASED (4) after it: the same state, applied, no second acknowledge.
    $this->remote = rtdnRemote($user, 'ACTIVE');
    rtdnPush(4, 'm-purchase')->assertOk()->assertJsonPath('outcome', 'applied');
    expect($this->acks)->toBe(1);

    // SUBSCRIPTION_RENEWED (2): the period moves forward.
    $renewedTo = now()->addMonths(2)->toIso8601ZuluString();
    $this->remote = rtdnRemote($user, 'ACTIVE', $renewedTo);
    rtdnPush(2, 'm-renew')->assertOk()->assertJsonPath('outcome', 'applied');
    expect(rtdnRow()->current_period_end->utc()->toIso8601ZuluString())->toBe($renewedTo)
        ->and(rtdnRow()->status->value)->toBe('active');

    // SUBSCRIPTION_CANCELED (3): still Pro until the paid period ends.
    $this->remote = rtdnRemote($user, 'CANCELED', $renewedTo);
    rtdnPush(3, 'm-cancel')->assertOk()->assertJsonPath('outcome', 'applied');
    expect(rtdnRow()->status->value)->toBe('cancelled')
        ->and($user->fresh()->plan()->key->value)->toBe('pro');

    // SUBSCRIPTION_REVOKED (12, refund): ends now, whatever the period says.
    rtdnPush(12, 'm-refund')->assertOk()->assertJsonPath('outcome', 'applied');
    expect(rtdnRow()->status->value)->toBe('expired')
        ->and($user->fresh()->plan()->key->value)->toBe('free');
});

it('handles a duplicate delivery (same messageId) once', function () {
    $user = User::factory()->create();
    $this->remote = rtdnRemote($user, 'ACTIVE');
    $this->actingAs($user)->postJson('/api/v1/billing/google/verify', ['purchase_token' => RTDN_TOKEN, 'product_id' => 'pro_monthly'])->assertSuccessful();
    app('auth')->forgetGuards();

    $this->remote = rtdnRemote($user, 'CANCELED');
    rtdnPush(3, 'm-dup')->assertOk()->assertJsonPath('outcome', 'applied');

    // Google changes its mind; the same message delivered again changes nothing.
    $this->remote = rtdnRemote($user, 'ACTIVE');
    rtdnPush(3, 'm-dup')->assertOk()->assertJsonPath('outcome', 'duplicate');
    expect(rtdnRow()->status->value)->toBe('cancelled');
});

it('refuses a push the verifier does not accept, and changes nothing', function () {
    $user = User::factory()->create();
    $this->remote = rtdnRemote($user, 'ACTIVE');
    $this->actingAs($user)->postJson('/api/v1/billing/google/verify', ['purchase_token' => RTDN_TOKEN, 'product_id' => 'pro_monthly'])->assertSuccessful();
    app('auth')->forgetGuards();

    $this->remote = rtdnRemote($user, 'EXPIRED', now()->subDay()->toIso8601ZuluString());
    rtdnPush(13, 'm-forged', bearer: 'forged')->assertUnauthorized();

    expect(rtdnRow()->status->value)->toBe('active');
});
