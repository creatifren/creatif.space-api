<?php

use Illuminate\Support\Facades\Http;

/*
 * creem:check-products: our prices match Creem's, and — since the trial was
 * removed (13 Nov 2026) — no product has a trial. The trial lives on the
 * Creem product, so a product that still has one would start a trial on
 * checkout whatever the API sends.
 */

function creemProducts(array $override = []): void
{
    $products = array_merge([
        'prod_pro_monthly' => [999, 'recurring', null], 'prod_pro_yearly' => [9990, 'recurring', null],
        'prod_max_monthly' => [1499, 'recurring', null], 'prod_max_yearly' => [14990, 'recurring', null],
        'prod_credits' => [100, 'onetime', null],
    ], $override);
    Http::fake(function ($request) use ($products) {
        $id = basename(parse_url($request->url(), PHP_URL_PATH));
        [$price, $type, $trial] = $products[$id];

        return Http::response(['id' => $id, 'price' => $price, 'currency' => 'USD', 'billing_type' => $type, 'trial_period_days' => $trial]);
    });
}

it('passes when every Creem product matches our prices and none has a trial, and says so per product', function () {
    creemProducts(['prod_max_yearly' => [14990, 'recurring', 0]]);

    $this->artisan('creem:check-products')
        ->expectsOutputToContain('pro_monthly (prod_pro_monthly): ok, trial: none')
        ->expectsOutputToContain('max_yearly (prod_max_yearly): ok, trial: none')
        ->expectsOutputToContain('credits (prod_credits): ok, trial: none')
        ->assertSuccessful();
});

it('fails when a Creem price drifts from ours', function () {
    creemProducts(['prod_max_yearly' => [14900, 'recurring', null]]);
    $this->artisan('creem:check-products')
        ->expectsOutputToContain('price 14900 ≠ 14990')
        ->assertFailed();
});

it('fails, naming the product and its trial, when a product still has a trial', function () {
    creemProducts(['prod_pro_monthly' => [999, 'recurring', 7], 'prod_max_yearly' => [14990, 'recurring', 14]]);

    $this->artisan('creem:check-products')
        ->expectsOutputToContain('pro_monthly (prod_pro_monthly): has a 7-day trial: remove it in Creem (every plan is charged at checkout) (trial: 7 days)')
        ->expectsOutputToContain('max_yearly (prod_max_yearly): has a 14-day trial')
        ->expectsOutputToContain('pro_yearly (prod_pro_yearly): ok, trial: none')
        ->assertFailed();
});

it('no longer checks the old no-trial twins', function () {
    config(['services.creem.legacy_notrial.pro_monthly' => 'prod_pro_monthly_notrial']);
    creemProducts();

    $this->artisan('creem:check-products')
        ->doesntExpectOutputToContain('notrial')
        ->assertSuccessful();
});
