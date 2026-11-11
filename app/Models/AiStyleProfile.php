<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one user writes, for AI captions: tone, emoji use, how many hashtags,
 * language and a few captions of their own. One row per user; no row means
 * the defaults (a few emoji, DEFAULT_HASHTAGS hashtags, the brief's language).
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $tone
 * @property string $emoji_use none | light | heavy
 * @property int $hashtag_count
 * @property string|null $language
 * @property list<string>|null $sample_captions
 */
class AiStyleProfile extends Model
{
    public const EMOJI_USE = ['none', 'light', 'heavy'];

    public const DEFAULT_HASHTAGS = 5;

    public const MAX_HASHTAGS = 30;

    public const MAX_SAMPLES = 5;

    protected $fillable = ['tone', 'emoji_use', 'hashtag_count', 'language', 'sample_captions'];

    protected $attributes = [
        'emoji_use' => 'light',
        'hashtag_count' => self::DEFAULT_HASHTAGS,
    ];

    protected function casts(): array
    {
        return [
            'hashtag_count' => 'integer',
            'sample_captions' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
