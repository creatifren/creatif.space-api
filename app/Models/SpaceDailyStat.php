<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SpaceDailyStatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The daily chart, after the raw events are gone.
 *
 * @property int $id
 * @property int $space_id
 * @property CarbonImmutable $date
 * @property int $views
 * @property int $unique_visitors
 * @property int $ai_crawler_hits
 * @property list<array{host: string, count: int}>|null $top_referrers
 * @property-read Space $space
 */
class SpaceDailyStat extends Model
{
    /** @use HasFactory<SpaceDailyStatFactory> */
    use HasFactory;

    protected $fillable = [
        'space_id',
        'date',
        'views',
        'unique_visitors',
        'ai_crawler_hits',
        'top_referrers',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // date:Y-m-d, not plain date: a bare `date` cast writes back a
            // full datetime, and the upsert then never matches the row it
            // wrote yesterday.
            'date' => 'date:Y-m-d',
            'views' => 'integer',
            'unique_visitors' => 'integer',
            'ai_crawler_hits' => 'integer',
            'top_referrers' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Space, $this>
     */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }
}
