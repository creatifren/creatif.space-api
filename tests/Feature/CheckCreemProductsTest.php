<?php

use Illuminate\Support\Facades\Http;

function creemProducts(array $override = []): void
{
    $products = array_merge([
        'prod_pro_monthly' => [999, 'recurring'], 'prod_pro_yearly' => [9990, 'recurring'],
        'prod_max_monthly' => [1499, 'recurring'], 'prod_max_yearly' => [14990, 'recurring'],
        'prod_credits' => [100, 'onetime'],
    ], $override);
    Http::fake(function ($request) use ($products) {
        $id = basename(parse_url($request->url(), PHP_URL_PATH));
        [$price, $type] = $products[$id];

        return Http::response(['id' => $id, 'price' => $price, 'currency' => 'USD', 'billing_type' => $type]);
    });
}

it('passes when every Creem product matches our prices', function () {
    creemProducts();
    $this->artisan('creem:check-products')->assertSuccessful();
});

it('fails when a Creem price drifts from ours', function () {
    creemProducts(['prod_max_yearly' => [14900, 'recurring']]);
    $this->artisan('creem:check-products')
        ->expectsOutputToContain('price 14900 ≠ 14990')
        ->assertFailed();
});
