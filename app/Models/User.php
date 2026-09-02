<?php

namespace App\Models;

use App\Enums\NotificationType;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property string $ulid
 * @property string $name
 * @property string $email
 * @property string|null $google_id
 * @property string|null $avatar_url
 * @property string $locale
 * @property string $theme
 * @property UserStatus $status
 * @property bool $is_staff
 * @property CarbonImmutable|null $email_verified_at
 * @property CarbonImmutable|null $onboarded_at
 * @property CarbonImmutable|null $suspended_at
 * @property string|null $suspended_reason
 * @property CarbonImmutable|null $created_at
 * @property-read Handle|null $handle
 * @property-read Profile|null $profile
 */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'google_id',
        'avatar_url',
        'locale',
        'theme',
        // Managed from the Filament admin panel only — API requests never
        // mass-assign these (API input is whitelisted per endpoint).
        'status',
        'suspended_at',
        'suspended_reason',
        'is_staff',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'google_id',
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'onboarded_at' => 'datetime',
            'suspended_at' => 'datetime',
            'is_staff' => 'boolean',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    /**
     * @return HasOne<Handle, $this>
     */
    public function handle(): HasOne
    {
        return $this->hasOne(Handle::class);
    }

    /**
     * @return HasOne<Profile, $this>
     */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /**
     * @return HasMany<DriveAccount, $this>
     */
    public function driveAccounts(): HasMany
    {
        return $this->hasMany(DriveAccount::class);
    }

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * @return HasMany<SocialPost, $this>
     */
    public function socialPosts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }

    /**
     * @return HasMany<File, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    /**
     * @return HasMany<Space, $this>
     */
    public function spaces(): HasMany
    {
        return $this->hasMany(Space::class);
    }

    /**
     * @return HasMany<FileRequest, $this>
     */
    public function fileRequests(): HasMany
    {
        return $this->hasMany(FileRequest::class);
    }

    /**
     * @return HasMany<NotificationPreference, $this>
     */
    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * @return HasMany<WalletTransaction, $this>
     */
    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /**
     * @return HasMany<Withdrawal, $this>
     */
    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }

    /**
     * The seats on this person's own Team plan.
     *
     * @return HasMany<TeamMember, $this>
     */
    public function teamMembers(): HasMany
    {
        return $this->hasMany(TeamMember::class, 'owner_id');
    }

    /**
     * Their side of the affiliate program, if they applied at all.
     *
     * @return HasOne<Affiliate, $this>
     */
    public function affiliate(): HasOne
    {
        return $this->hasOne(Affiliate::class);
    }

    /**
     * How this person came to be a customer — null for almost everyone.
     *
     * @return HasOne<Referral, $this>
     */
    public function referral(): HasOne
    {
        return $this->hasOne(Referral::class, 'referred_user_id');
    }

    /**
     * The subscription that entitles this user to a paid plan right now —
     * active, or inside the grace window after a missed payment.
     */
    public function activeSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])
            ->with('plan')
            ->latest('id')
            ->first();
    }

    /**
     * This person's plan. Derived, never stored: no active subscription is
     * exactly what Free means.
     *
     * Not memoised on purpose — one indexed lookup is cheap, and a cached
     * plan goes stale the moment a payment or the daily expiry job moves
     * the subscription underneath it.
     */
    public function plan(): Plan
    {
        $subscription = $this->activeSubscription();

        return $subscription === null ? Plan::free() : $subscription->plan;
    }

    /**
     * The client identities signed in with this person's email — the bridge
     * that lets Insights show approvals they gave in someone else's Space.
     *
     * @return HasMany<Client, $this>
     */
    public function clientIdentities(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /**
     * Does this user want `$type` on `$channel` ("email" or "bell")?
     * No stored row means yes: a new notification type starts switched on.
     */
    public function wants(NotificationType $type, string $channel): bool
    {
        $preference = $this->notificationPreferences
            ->firstWhere('type', $type);

        if ($preference === null) {
            return true;
        }

        return $channel === 'email'
            ? $preference->email_enabled
            : $preference->bell_enabled;
    }

    /**
     * Assign a public ULID on creation.
     */
    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            $user->ulid ??= (string) str()->ulid();
        });
    }

    /**
     * Route model binding uses the public ULID, never the internal id.
     */
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * Only internal staff may reach the Filament admin panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_staff && $this->status === UserStatus::Active;
    }
}
