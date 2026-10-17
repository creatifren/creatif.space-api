<?php

use App\Enums\UserStatus;
use App\Models\Handle;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

/**
 * Email + password next to Google. What matters most is what must not
 * happen: an address nobody confirmed is never signed in, because Google
 * sign-in and team invites both trust the email on the account.
 */
beforeEach(fn () => Notification::fake());

function passwordUser(array $attributes = []): User
{
    return User::factory()->create([
        'google_id' => null,
        'password' => 'correct-horse',
        ...$attributes,
    ]);
}

function verifyUrl(User $user, ?string $hash = null): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->getKey(),
        'hash' => $hash ?? sha1($user->email),
    ]);
}

it('registers without signing in and sends the verification link', function () {
    $this->postJson('/auth/register', ['email' => 'rani@example.test', 'password' => 'correct-horse'])
        ->assertStatus(202);

    $user = User::query()->firstWhere('email', 'rani@example.test');

    expect($user->email_verified_at)->toBeNull()
        ->and(Hash::check('correct-horse', $user->password))->toBeTrue();
    Notification::assertSentTo($user, VerifyEmail::class);
    $this->assertGuest();
});

it('answers a taken address the same, and mails its owner a reset link instead', function () {
    $user = passwordUser(['email' => 'taken@example.test']);

    $fresh = $this->postJson('/auth/register', ['email' => 'new@example.test', 'password' => 'correct-horse'])
        ->assertStatus(202)->json();
    $taken = $this->postJson('/auth/register', ['email' => 'Taken@example.test', 'password' => 'other-horse'])
        ->assertStatus(202)->json();

    expect($taken)->toBe($fresh)
        ->and(Hash::check('correct-horse', $user->fresh()->password))->toBeTrue()
        ->and(User::query()->where('email', 'taken@example.test')->count())->toBe(1);
    Notification::assertSentTo($user, ResetPassword::class);
    Notification::assertNotSentTo($user, VerifyEmail::class);
    $this->assertGuest();
});

it('stores the address lowercased and signs in whatever the case', function () {
    $this->postJson('/auth/register', ['email' => 'Rani@Example.test', 'password' => 'correct-horse'])
        ->assertStatus(202);

    $user = User::query()->firstWhere('email', 'rani@example.test');
    expect($user)->not->toBeNull();

    $user->markEmailAsVerified();
    $this->postJson('/auth/login', ['email' => 'RANI@example.test', 'password' => 'correct-horse'])
        ->assertNoContent();
    $this->assertAuthenticatedAs($user);
});

it('does not let the handle check use up the signup throttle', function () {
    foreach (range(1, 6) as $i) {
        $this->getJson('/api/v1/handles/availability?name=rani'.$i)->assertOk();
    }

    $this->postJson('/auth/register', ['email' => 'rani@example.test', 'password' => 'correct-horse'])
        ->assertStatus(202);
});

it('signs in a verified account', function () {
    $user = passwordUser();

    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'correct-horse'])
        ->assertNoContent();

    $this->assertAuthenticatedAs($user);
});

it('never signs in an unconfirmed address, and sends a fresh link', function () {
    $user = passwordUser(['email_verified_at' => null]);

    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'correct-horse'])
        ->assertForbidden()->assertJson(['reason' => 'unverified']);

    $this->assertGuest();
    Notification::assertSentTo($user, VerifyEmail::class);
});

it('says the same thing for a wrong password, a Google-only account and nobody', function (string $email, string $password) {
    passwordUser(['email' => 'real@example.test']);
    User::factory()->create(['email' => 'google@example.test']);

    $this->postJson('/auth/login', ['email' => $email, 'password' => $password])
        ->assertStatus(422)->assertJsonValidationErrors('email');

    $this->assertGuest();
})->with([
    'wrong password' => ['real@example.test', 'nope-nope'],
    'google only' => ['google@example.test', 'correct-horse'],
    'no account' => ['ghost@example.test', 'correct-horse'],
]);

it('keeps a suspended account out', function () {
    $user = passwordUser(['status' => UserStatus::Suspended]);

    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'correct-horse'])
        ->assertForbidden()->assertJson(['reason' => 'suspended']);

    $this->assertGuest();
});

it('verifies and signs in from the email link', function () {
    $user = passwordUser(['email_verified_at' => null]);

    $this->get(verifyUrl($user))
        ->assertRedirect(config('app.frontend_url').'/home');

    expect($user->fresh()->email_verified_at)->not->toBeNull();
    $this->assertAuthenticatedAs($user);
});

it('gives the account an address from its email, skipping taken and reserved ones', function () {
    Handle::factory()->reserved()->create(['name' => 'admin']);
    Handle::factory()->create(['name' => 'admin2']);
    $user = passwordUser(['email' => 'Ad.min@example.test', 'email_verified_at' => null]);

    $this->get(verifyUrl($user));

    $user->refresh();
    expect($user->handle->name)->toBe('admin3')
        ->and($user->onboarded_at)->not->toBeNull()
        ->and($user->profile)->not->toBeNull();
});

it('signs in from the link only once', function () {
    $user = passwordUser(['email_verified_at' => null]);
    $url = verifyUrl($user);

    $this->get($url)->assertRedirect(config('app.frontend_url').'/home');
    auth()->guard('web')->logout();

    $this->get($url)->assertRedirect(config('app.frontend_url').'/login?error=link');
    $this->assertGuest();
});

it('rejects a link for a different address or without a signature', function () {
    $user = passwordUser(['email_verified_at' => null]);

    $this->get(verifyUrl($user, sha1('other@example.test')))
        ->assertRedirect(config('app.frontend_url').'/login?error=link');
    $this->get("/auth/email/verify/{$user->getKey()}/".sha1($user->email))
        ->assertRedirect(config('app.frontend_url').'/login?error=link');

    expect($user->fresh()->email_verified_at)->toBeNull();
    $this->assertGuest();
});

it('answers forgot-password the same whether the address exists or not', function () {
    $user = passwordUser();

    $known = $this->postJson('/auth/forgot-password', ['email' => $user->email])->assertOk()->json();
    $unknown = $this->postJson('/auth/forgot-password', ['email' => 'ghost@example.test'])->assertOk()->json();

    expect($known)->toBe($unknown);
    Notification::assertSentTo($user, ResetPassword::class);
});

it('points the reset link at the frontend', function () {
    $user = passwordUser();

    $url = (new ResetPassword('tok'))->toMail($user)->actionUrl;

    expect($url)->toBe(config('app.frontend_url').'/reset-password?token=tok&email='.urlencode($user->email));
});

it('sets a new password from the reset link, which also confirms the address', function () {
    $user = passwordUser(['email_verified_at' => null]);
    $token = Password::createToken($user);

    $this->postJson('/auth/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-horse-battery',
    ])->assertOk();

    $user->refresh();
    expect(Hash::check('new-horse-battery', $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();
});

it('refuses a reset with a made-up token', function () {
    $user = passwordUser();

    $this->postJson('/auth/reset-password', [
        'token' => 'made-up',
        'email' => $user->email,
        'password' => 'new-horse-battery',
    ])->assertStatus(422);

    expect(Hash::check('correct-horse', $user->fresh()->password))->toBeTrue();
});
