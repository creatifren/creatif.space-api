<?php

namespace App\Models;

use App\Enums\SpaceEventType;
use Carbon\CarbonImmutable;
use Database\Factories\SpaceEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a visitor did. Written by the beacon, read by the aggregation
 * job and by Premium analytics; deleted after 90 days.
 *
 * @property int $id
 * @property int $space_id
 * @property SpaceEventType $type
 * @property string $visitor_hash
 * @property int|null $client_id
 * @property int|null $space_item_id
 * @property string|null $referrer_host
 * @property bool $is_ai_crawler
 * @property string|null $crawler_name
 * @property string|null $country
 * @property CarbonImmutable|null $created_at
 * @property-read Space $space
 * @property-read Client|null $client
 */
class SpaceEvent extends Model
{
    /** @use HasFactory<SpaceEventFactory> */
    use HasFactory;

    /**
     * Rows are never updated — an event is a fact about a moment.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'space_id',
        'type',
        'visitor_hash',
        'client_id',
        'space_item_id',
        'referrer_host',
        'is_ai_crawler',
        'crawler_name',
        'country',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SpaceEventType::class,
            'is_ai_crawler' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Space, $this>
     */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
