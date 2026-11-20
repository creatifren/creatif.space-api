<?php

namespace App\Models\Concerns;

use App\Support\SampleData;
use Illuminate\Database\Eloquent\Builder;

/**
 * A model that can hold sample rows (is_demo, App\Support\SampleData).
 *
 * Connected apps and API keys (MCP, bearer tokens) never see sample rows:
 * the global scope adds `is_demo = false` while a token request is being
 * served. The app (session) sees them, with their Sample badge. `real()`
 * is for every count, email and job that must leave them out.
 */
trait HasSampleFlag
{
    public static function bootHasSampleFlag(): void
    {
        static::addGlobalScope('hide_sample_from_tokens', function (Builder $query) {
            if (SampleData::hiddenForThisRequest()) {
                $query->where($query->qualifyColumn('is_demo'), false);
            }
        });
    }

    public function initializeHasSampleFlag(): void
    {
        $this->mergeCasts(['is_demo' => 'boolean']);
    }

    /** @param  Builder<static>  $query */
    public function scopeReal(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_demo'), false);
    }
}
