<?php

use App\Http\Controllers\Api\V1\GooglePlayCreditsController;
use App\Models\CreditPurchase;
use App\Models\User;
use App\Services\Analytics;
use App\Services\GooglePlayClient;
use App\Support\Credits;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * P10 · POST /billing/google/credits: a Play Credits top-up (one-time,
 * consumable) → Credits once per purchase token, then consumed.
 */

beforeEach(function () {
    Cache::flush();
    Analytics::fake();
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private);
    $path = tempnam(sys_get_temp_dir(), 'gpc-sa-');
    file_put_contents($path, json_encode([
        'type' => 'service_account',
        'client_email' => 'play-api@example-project.iam.gserviceaccount.com',
        'private_key' => $private,
        'token_uri' => 'https://oauth2.googleapis.com/token',
    ]));
    config([
        'services.google_play.package_name' => 'space.creatif.app',
        'services.google_play.service_account_json_path' => $path,
    ]);

    $this->gpc = new stdClass;
    $this->gpc->remote = [];
    $this->gpc->consumed = [];
    Http::preventStrayRequests();
    Http::fake([
        GooglePlayClient::TOKEN_URL => fn () => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599, 'token_type' => 'Bearer']),
        'androidpublisher.googleapis.com/*' => function (HttpRequest $request) {
            if (preg_match('#/purchases/products/([^/]+)/tokens/([^/:]+):consume$#', $request->url(), $m)) {
                $this->gpc->consumed[] = urldecode($m[2]);

                return Http::response([], 204);
            }
            if (preg_match('#/purchases/products/([^/]+)/tokens/([^/:?]+)$#', $request->url(), $m)) {
                $body = $this->gpc->remote[urldecode($m[2])] ?? null;

                return $body === null ? Http::response(['error' => ['code' => 404]], 404) : Http::response($body);
            }

            return Http::response([], 500);
        },
    ]);
});

function gpcBought(User $user, array $over = []): array
{
    return ['purchaseState' => 0, 'consumptionState' => 0, 'acknowledgementState' => 0, 'obfuscatedExternalAccountId' => $user->ulid, 'orderId' => 'GPA.1234', 'quantity' => 1, ...$over];
}

it('adds the Credits once and consumes the purchase', function () {
    $user = User::factory()->create();
    $this->gpc->remote['tok-1'] = gpcBought($user);
    $before = Credits::total($user);

    $this->actingAs($user)->postJson('/api/v1/billing/google/credits', ['purchase_token' => 'tok-1', 'product_id' => 'credits_100000'])
        ->assertOk()->assertJson(['credits_added' => 100000, 'already_added' => false]);
    expect(Credits::total($user))->toBe($before + 100000)
        ->and($this->gpc->consumed)->toBe(['tok-1'])
        ->and(CreditPurchase::query()->where('creem_checkout_id', GooglePlayCreditsController::handle('tok-1'))->value('payment_method'))->toBe('google_play');

    // A retry (the app restores on start) adds nothing.
    $this->actingAs($user)->postJson('/api/v1/billing/google/credits', ['purchase_token' => 'tok-1', 'product_id' => 'credits_100000'])
        ->assertOk()->assertJson(['credits_added' => 0, 'already_added' => true]);
    expect(Credits::total($user))->toBe($before + 100000);
});

it('waits on a pending payment', function () {
    $user = User::factory()->create();
    $this->gpc->remote['tok-2'] = gpcBought($user, ['purchaseState' => 2]);

    $this->actingAs($user)->postJson('/api/v1/billing/google/credits', ['purchase_token' => 'tok-2', 'product_id' => 'credits_50000'])
        ->assertStatus(202)->assertJson(['reason' => 'pending']);
    expect($this->gpc->consumed)->toBe([]);
});

it('refuses another account, an unknown product and a cancelled purchase', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $this->gpc->remote['tok-3'] = gpcBought($other);
    $this->gpc->remote['tok-4'] = gpcBought($user, ['purchaseState' => 1]);

    $this->actingAs($user)->postJson('/api/v1/billing/google/credits', ['purchase_token' => 'tok-3', 'product_id' => 'credits_50000'])
        ->assertStatus(403)->assertJson(['reason' => 'account_mismatch']);
    $this->actingAs($user)->postJson('/api/v1/billing/google/credits', ['purchase_token' => 'tok-3', 'product_id' => 'pro_monthly'])
        ->assertStatus(422)->assertJson(['reason' => 'unknown_product']);
    $this->actingAs($user)->postJson('/api/v1/billing/google/credits', ['purchase_token' => 'tok-4', 'product_id' => 'credits_50000'])
        ->assertStatus(422)->assertJson(['reason' => 'invalid_token']);
    expect($this->gpc->consumed)->toBe([]);
});
