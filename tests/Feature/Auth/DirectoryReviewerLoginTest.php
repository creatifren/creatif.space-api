<?php

use App\Enums\UserStatus;
use App\Models\User;
use App\Support\DirectoryReviewer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * G3: a directory reviewer (staff-set flag) signs in with a password at
 * /auth/reviewer/login — no verification, no magic link — and can then
 * complete the MCP OAuth flow. An account without the flag never signs in
 * there, whatever its password. Every attempt is audited.
 */
const REVIEWER_PASSWORD = 'reviewer-password-123';

beforeEach(function () {
    RateLimiter::clear('reviewer-login:'.DirectoryReviewer::emailHash('reviewer@example.test'));
});

function reviewer(array $attributes = []): User
{
    $user = User::factory()->create(['email' => 'reviewer@example.test', 'google_id' => null, 'email_verified_at' => null, ...$attributes]);
    test()->artisan('reviewer:grant', ['email' => $user->email, '--password' => true])
        ->expectsQuestion('Password (at least 12 characters)', REVIEWER_PASSWORD)
        ->assertSuccessful();

    return $user->refresh();
}

function reviewerLogin(TestCase $test, string $email, string $password)
{
    return $test->postJson('/auth/reviewer/login', ['email' => $email, 'password' => $password]);
}

function auditOutcomes(): array
{
    return DB::table('directory_reviewer_logins')->orderBy('id')->pluck('outcome')->all();
}

it('signs a flagged reviewer in with the password and completes the MCP OAuth flow', function () {
    ensurePassportKeys();
    config(['mcp.introspection_secret' => 'shh-test-secret']);
    $user = reviewer();

    reviewerLogin($this, 'Reviewer@Example.test', REVIEWER_PASSWORD)->assertNoContent();
    $this->assertAuthenticatedAs($user);

    // The browser half of OAuth with this session (no actingAs), then the token.
    $client = mcpClient();
    [$verifier, $challenge] = pkcePair();
    $redirect = $client->redirect_uris[0];
    $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(), 'redirect_uri' => $redirect, 'response_type' => 'code',
        'scope' => 'read', 'state' => 'xyz', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    ]))->assertOk();
    $approve = $this->post('/oauth/authorize', ['state' => 'xyz', 'client_id' => $client->getKey(), 'auth_token' => session('authToken')]);
    $approve->assertRedirect();
    parse_str((string) parse_url((string) $approve->headers->get('Location'), PHP_URL_QUERY), $query);
    $tokens = $this->post('/oauth/token', [
        'grant_type' => 'authorization_code', 'client_id' => $client->getKey(), 'redirect_uri' => $redirect,
        'code_verifier' => $verifier, 'code' => $query['code'],
    ])->assertOk()->json();

    app('auth')->forgetGuards();
    $this->withHeaders(['X-MCP-Secret' => 'shh-test-secret'])
        ->postJson('/api/v1/oauth/introspect', ['token' => $tokens['access_token']])
        ->assertOk()
        ->assertJsonPath('active', true)
        ->assertJsonPath('user_ulid', $user->ulid);

    expect(auditOutcomes())->toBe([DirectoryReviewer::GRANTED, DirectoryReviewer::SUCCESS]);
});

it('needs no email verification step: the grant vouches for the address', function () {
    $user = reviewer();

    expect($user->is_directory_reviewer)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check(REVIEWER_PASSWORD, $user->password))->toBeTrue();
});

it('never signs in an account without the flag, even with its correct password', function (array $attributes) {
    $user = User::factory()->create(['email' => 'reviewer@example.test', 'password' => REVIEWER_PASSWORD, ...$attributes]);

    reviewerLogin($this, $user->email, REVIEWER_PASSWORD)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'That email and password don’t match.']);
    $this->assertGuest();
    expect(auditOutcomes())->toBe([DirectoryReviewer::REJECTED]);
})->with([
    'verified password user' => [['email_verified_at' => now(), 'google_id' => null]],
    'Google user with a password' => [['google_id' => 'g-123']],
    'staff' => [['is_staff' => true]],
]);

it('refuses a Google-only account and an unknown address the same way', function () {
    User::factory()->create(['email' => 'reviewer@example.test', 'password' => null, 'google_id' => 'g-1']);

    reviewerLogin($this, 'reviewer@example.test', 'anything-at-all')->assertUnprocessable();
    reviewerLogin($this, 'nobody@example.test', 'anything-at-all')->assertUnprocessable();
    $this->assertGuest();
});

it('refuses a wrong password for a reviewer', function () {
    reviewer();

    reviewerLogin($this, 'reviewer@example.test', 'not-the-password')->assertUnprocessable();
    $this->assertGuest();
    expect(auditOutcomes())->toBe([DirectoryReviewer::GRANTED, DirectoryReviewer::REJECTED]);
});

it('refuses a suspended reviewer', function () {
    $user = reviewer();
    $user->forceFill(['status' => UserStatus::Suspended])->save();

    reviewerLogin($this, $user->email, REVIEWER_PASSWORD)->assertForbidden();
    $this->assertGuest();
});

it('rate-limits by address, and audits the throttled attempt', function () {
    reviewer();

    foreach (range(1, DirectoryReviewer::MAX_ATTEMPTS) as $_) {
        reviewerLogin($this, 'reviewer@example.test', 'wrong-password')->assertUnprocessable();
    }
    reviewerLogin($this, 'reviewer@example.test', REVIEWER_PASSWORD)->assertStatus(429)->assertHeader('Retry-After');
    $this->assertGuest();
    expect(auditOutcomes())->toContain(DirectoryReviewer::THROTTLED);
});

it('audits the address as a hash only', function () {
    reviewerLogin($this, 'Someone@Example.test', 'whatever-password');

    $row = DB::table('directory_reviewer_logins')->sole();
    expect($row->email_hash)->toBe(hash('sha256', 'someone@example.test'))
        ->and(json_encode($row))->not->toContain('someone@');
});

describe('reviewer:grant', function () {
    it('only flags an account that already exists', function () {
        $this->artisan('reviewer:grant', ['email' => 'missing@example.test'])->assertFailed();
        expect(User::query()->where('email', 'missing@example.test')->exists())->toBeFalse();
    });

    it('generates a password when none is asked for, and it works', function () {
        $user = User::factory()->create(['email' => 'reviewer@example.test']);

        $this->artisan('reviewer:grant', ['email' => $user->email])->assertSuccessful();
        expect($user->refresh()->is_directory_reviewer)->toBeTrue()->and($user->password)->not->toBeNull();
    });

    it('refuses a short password', function () {
        $user = User::factory()->create(['email' => 'reviewer@example.test']);

        $this->artisan('reviewer:grant', ['email' => $user->email, '--password' => true])
            ->expectsQuestion('Password (at least 12 characters)', 'short')
            ->assertFailed();
        expect($user->refresh()->is_directory_reviewer)->toBeFalse();
    });

    it('revokes: the flag and the password go, and reviewer login fails', function () {
        $user = reviewer();

        $this->artisan('reviewer:grant', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
        expect($user->refresh()->is_directory_reviewer)->toBeFalse()->and($user->password)->toBeNull();
        reviewerLogin($this, $user->email, REVIEWER_PASSWORD)->assertUnprocessable();
    });
});
