<?php

namespace App\Console\Commands;

use App\Enums\PlanKey;
use App\Models\Plan;
use App\Services\CreemService;
use App\Support\Trial;
use Illuminate\Console\Command;
use Throwable;

/**
 * The price we show must be the price Creem charges. Plans keep their price
 * in our table and their product in env, so the two can drift apart
 * silently — run this after touching either, and before going live.
 */
class CheckCreemProducts extends Command
{
    protected $signature = 'creem:check-products';

    protected $description = 'Fail when a Creem product price, currency or billing type differs from ours';

    public function handle(CreemService $creem): int
    {
        // Credits: one unit is $1, bought `units` times at checkout. The
        // trial lives on Creem's Pro products (Trial); Max and the no-trial
        // Pro twins must have none, or a trial is given where none is meant.
        $expected = ['credits' => [100, 'onetime', null]];
        foreach (Plan::query()->where('is_active', true)->where('price_monthly', '>', 0)->get() as $plan) {
            $trial = $plan->key === PlanKey::Pro ? Trial::days() : null;
            $expected[$plan->key->value.'_monthly'] = [$plan->price_monthly, 'recurring', $trial];
            $expected[$plan->key->value.'_yearly'] = [$plan->price_yearly, 'recurring', $trial];
            if ($plan->key === PlanKey::Pro) {
                foreach (['monthly', 'yearly'] as $period) {
                    if (Trial::noTrialProduct($period) !== null) {
                        $expected["pro_{$period}_notrial"] = [$plan->priceFor($period), 'recurring', null];
                    }
                }
            }
        }

        $failed = false;
        foreach ($expected as $key => [$price, $type, $trialDays]) {
            $id = config("services.creem.products.{$key}");
            if (! is_string($id) || $id === '') {
                $this->error("{$key}: CREEM_PRODUCT_".strtoupper($key).' is not set.');
                $failed = true;

                continue;
            }
            try {
                $product = $creem->fetchProduct($id);
            } catch (Throwable $e) {
                $this->error("{$key} ({$id}): {$e->getMessage()}");
                $failed = true;

                continue;
            }
            $problems = array_filter([
                ($product['id'] ?? null) !== $id ? 'different id returned' : null,
                ($product['price'] ?? null) !== $price ? 'price '.json_encode($product['price'] ?? null)." ≠ {$price}" : null,
                ($product['currency'] ?? null) !== 'USD' ? 'currency '.json_encode($product['currency'] ?? null).' ≠ "USD"' : null,
                ($product['billing_type'] ?? null) !== $type ? 'billing_type '.json_encode($product['billing_type'] ?? null)." ≠ \"{$type}\"" : null,
                ($product['trial_period_days'] ?? null) !== $trialDays ? 'trial_period_days '.json_encode($product['trial_period_days'] ?? null).' ≠ '.json_encode($trialDays) : null,
            ]);
            if ($problems === []) {
                $this->info("{$key} ({$id}): ok");
            } else {
                $this->error("{$key} ({$id}): ".implode('; ', $problems));
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
