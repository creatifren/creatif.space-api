<?php

namespace App\Models;

use App\Models\Contracts\WorkspaceOwned;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One client's monthly report (v7): built from Insight for the client's
 * accounts, shared by link (Link only by default), emailed to the client's
 * approver on the owner's schedule.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property int|null $client_label_id
 * @property string $month
 * @property string $token
 * @property string $visibility
 * @property string|null $summary
 * @property \Carbon\CarbonImmutable|null $sent_at
 * @property \Carbon\CarbonImmutable|null $revoked_at
 * @property int $open_count
 */
class ClientReport extends Model implements WorkspaceOwned
{
    public const VISIBILITY = ['link', 'public', 'private'];

    protected $fillable = ['user_id', 'client_label_id', 'month', 'visibility', 'summary', 'summary_edited_at', 'sent_at', 'sent_to', 'open_count', 'last_opened_at', 'revoked_at'];

    protected $hidden = ['token', 'token_hash'];

    protected function casts(): array
    {
        return [
            'summary_edited_at' => 'datetime',
            'sent_at' => 'datetime',
            'last_opened_at' => 'datetime',
            'revoked_at' => 'datetime',
            'open_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $report): void {
            $report->ulid ??= (string) Str::ulid();
            // cr_ tells the app's /report/{token} page this is a client report.
            $report->token ??= 'cr_'.Str::random(40);
            $report->token_hash = self::hashToken($report->token);
        });
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
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

    /** @return BelongsTo<ClientLabel, $this> */
    public function clientLabel(): BelongsTo
    {
        return $this->belongsTo(ClientLabel::class);
    }

    public function url(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/report/'.$this->token;
    }
}
