<?php

namespace App\Models;

use App\Enums\ProfileMode;
use App\Enums\SpaceViewMode;
use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property ProfileMode $mode
 * @property string|null $headline
 * @property string|null $bio
 * @property string|null $location
 * @property array<string, string>|null $socials
 * @property array<string, mixed>|null $appearance
 * @property array<string, mixed>|null $seo
 * @property array<string, mixed>|null $freelance
 * @property list<string>|null $categories
 * @property string|null $cover_url
 */
class Profile extends Model
{
    /** @use HasFactory<ProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'mode',
        'default_view_mode',
        'headline',
        'bio',
        'location',
        'socials',
        'appearance',
        'seo',
        'freelance',
        'categories',
        'cover_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => ProfileMode::class,
            'default_view_mode' => SpaceViewMode::class,
            'socials' => 'array',
            'appearance' => 'array',
            'seo' => 'array',
            'freelance' => 'array',
            'categories' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
