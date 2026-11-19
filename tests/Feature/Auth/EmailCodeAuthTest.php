<?php

use App\Models\User;
use App\Notifications\EmailSignInCode;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/* v7 sign-in: Google, or email + a 6-digit code. */

beforeEach(fn () => Notification::fake());

function sentCode(string $email): string
{
    $code = null;
    Notification::assertSentOnDemand(EmailSignInCode::class, function (EmailSignInCode $n, array $channels, AnonymousNotifiable $to) use ($email, &$code) {
        if (($to->routes['mail'] ?? null) !== $email) {
            return false;
        }
        $code = $n->code;

        return true;
    });

    return (string) $code;
}

it('makes an account from a new address on the first right code', function () {
    $this->postJson('/auth/email-code', ['email' => 'New.Person@Example.com'])->assertStatus(202);
    $code = sentCode('new.person@example.com');
    expect($code)->toMatch('/^\d{6}$/');

    $this->postJson('/auth/email-code/verify', ['email' => 'new.person@example.com', 'code' => $code])
        ->assertOk()
        ->assertJsonPath('data.new_account', true)
        ->assertJsonPath('data.next', '/onboarding');

    $user = User::query()->where('email', 'new.person@example.com')->firstOrFail();
    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->password)->toBeNull();
    $this->assertAuthenticatedAs($user);
});

it('signs a known address in, once per code', function () {
    $user = User::factory()->create(['email' => 'rizal@example.com']);
    $this->postJson('/auth/email-code', ['email' => 'rizal@example.com'])->assertStatus(202);
    $code = sentCode('rizal@example.com');

    $this->postJson('/auth/email-code/verify', ['email' => 'rizal@example.com', 'code' => $code])
        ->assertOk()
        ->assertJsonPath('data.new_account', false);
    $this->assertAuthenticatedAs($user);

    auth()->logout();
    $this->postJson('/auth/email-code/verify', ['email' => 'rizal@example.com', 'code' => $code])->assertStatus(422);
});

it('refuses a wrong code and locks after five tries', function () {
    $this->postJson('/auth/email-code', ['email' => 'try@example.com'])->assertStatus(202);
    $code = sentCode('try@example.com');
    $wrong = $code === '111111' ? '222222' : '111111';

    foreach (range(1, 5) as $_) {
        $this->postJson('/auth/email-code/verify', ['email' => 'try@example.com', 'code' => $wrong])->assertStatus(422);
    }
    // The right code no longer works after five misses: ask for a new one.
    $this->postJson('/auth/email-code/verify', ['email' => 'try@example.com', 'code' => $code])->assertStatus(422);
    $this->assertGuest();
});

it('answers the same for a suspended address and sends nothing', function () {
    User::factory()->create(['email' => 'gone@example.com', 'status' => 'suspended']);
    $this->postJson('/auth/email-code', ['email' => 'gone@example.com'])->assertStatus(202);
    Notification::assertNothingSent();
});
