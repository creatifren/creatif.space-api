<?php

namespace App\Models;

use App\Models\Concerns\HasSampleFlag;
use App\Models\Contracts\WorkspaceOwned;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A client (prototype v7): a label the owner puts on social accounts. One
 * account can carry several labels, and a post on that account belongs to
 * all of them — no "which client?" question anywhere.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string $name
 * @property string|null $initials
 * @property string $color
 * @property string|null $approver_email
 * @property string|null $approver_name
 * @property bool $report_email
 */
class ClientLabel extends Model implements WorkspaceOwned
{
    use HasSampleFlag;

    protected $fillable = ['user_id', 'name', 'initials', 'color', 'approver_email', 'approver_name', 'report_email'];

    /** The colour presets the app offers; any #RRGGBB is accepted. */
    public const COLORS = ['#C4A27A', '#3E6A8A', '#2F4A3E', '#A34A3C', '#7A5AA8', '#C9822B'];

    protected function casts(): array
    {
        return ['report_email' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $label): void {
            $label->ulid ??= (string) Str::ulid();
            $label->initials ??= self::initialsOf($label->name);
        });
    }

    public static function initialsOf(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = count($words) >= 2 ? mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1) : mb_substr(trim($name), 0, 2);

        return mb_strtoupper($out);
    }

    public function workspaceUserId(): ?int
    {
        return $this->user_id;
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<SocialAccount, $this> */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(SocialAccount::class, 'client_label_social_account');
    }

    /** @return HasMany<SocialApprovalLink, $this> */
    public function approvalLinks(): HasMany
    {
        return $this->hasMany(SocialApprovalLink::class);
    }

    /**
     * The short form screens and the MCP server show next to a post or account.
     *
     * @return array{id: string, name: string, initials: string|null, color: string}
     */
    public function toBadge(): array
    {
        return ['id' => $this->ulid, 'name' => $this->name, 'initials' => $this->initials, 'color' => $this->color];
    }
}
