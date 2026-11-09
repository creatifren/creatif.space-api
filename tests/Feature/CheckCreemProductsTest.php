<?php

use Illuminate\Support\Facades\Http;

function creemProducts(array $override = []): void
{
    $products = array_merge([
        'prod_pro_monthly' => [999, 'recurring', 7], 'prod_pro_yearly' => [9990, 'recurring', 7],
        'prod_max_monthly' => [1499, 'recurring', null], 'prod_max_yearly' => [14990, 'recurring', null],
        'prod_credits' => [100, 'onetime', null],
        'prod_pro_monthly_notrial' => [999, 'recurring', null], 'prod_pro_yearly_notrial' => [9990, 'recurring', null],
    ], $override);
    Http::fake(function ($request) use ($products) {
        $id = basename(parse_url($request->url(), PHP_URL_PATH));
        [$price, $type, $trial] = $products[$id];

        return Http::response(['id' => $id, 'price' => $price, 'currency' => 'USD', 'billing_type' => $type, 'trial_period_days' => $trial]);
    });
}

it('passes when every Creem product matches our prices', function () {
    creemProducts();
    $this->artisan('creem:check-products')->assertSuccessful();
});

it('fails when a Creem price drifts from ours', function () {
    creemProducts(['prod_max_yearly' => [14900, 'recurring', null]]);
    $this->artisan('creem:check-products')
        ->expectsOutputToContain('price 14900 ≠ 14990')
        ->assertFailed();
});

it('checks that the trial is on Pro only, and off on the no-trial twins', function () {
    config(['services.creem.products.pro_monthly_notrial' => 'prod_pro_monthly_notrial']);
    creemProducts(['prod_max_monthly' => [1499, 'recurring', 7], 'prod_pro_monthly_notrial' => [999, 'recurring', 7]]);

    $this->artisan('creem:check-products')
        ->expectsOutputToContain('max_monthly (prod_max_monthly): trial_period_days 7 ≠ null')
        ->expectsOutputToContain('pro_monthly_notrial (prod_pro_monthly_notrial): trial_period_days 7 ≠ null')
        ->assertFailed();
});

it('fails when Pro has lost its trial', function () {
    creemProducts(['prod_pro_yearly' => [9990, 'recurring', null]]);

    $this->artisan('creem:check-products')
        ->expectsOutputToContain('trial_period_days null ≠ 7')
        ->assertFailed();
});
