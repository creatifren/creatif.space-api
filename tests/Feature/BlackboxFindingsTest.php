<?php

use App\Models\User;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

/*
 * QA blackbox findings (live, 11 Nov): F1 reset-password enumeration,
 * F10 the throttle message, F8 cheap /up and /api/v1/plans, F9 docs URL.
 */

const RESET_FAILED = 'This password reset link is invalid or has expired.';

function bbUser(): User
{
    return User::factory()->create(['email' => 'rani@example.test', 'password' => 'correct-horse']);
}

describe('F1 reset-password never says whether the address exists', function () {
    it('answers an unknown email, a wrong token and an expired one alike', function () {
        $user = bbUser();
        $expired = Password::createToken($user);
        // Past the reset link's life (auth.passwords.users.expire, minutes).
        $this->travel((int) config('auth.passwords.users.expire', 60) + 1)->minutes();

        $cases = [
            'unknown email' => ['token' => 'whatever', 'email' => 'nobody@example.test'],
            'wrong token' => ['token' => 'made-up', 'email' => $user->email],
            'expired token' => ['token' => $expired, 'email' => $user->email],
        ];

        $bodies = [];
        foreach ($cases as $label => $fields) {
            $response = $this->postJson('/auth/reset-password', $fields + ['password' => 'new-horse-battery'])
                ->assertStatus(422)
                ->assertJsonPath('errors.email.0', RESET_FAILED);
            expect(strtolower((string) $response->getContent()))->not->toContain('find a user', $label);
            $bodies[$label] = $response->json();
        }

        // Byte-for-byte the same answer.
        expect($bodies['unknown email'])->toBe($bodies['wrong token'])
            ->and($bodies['wrong token'])->toBe($bodies['expired token']);
        expect(Hash::check('correct-horse', $user->fresh()->password))->toBeTrue();
    });

    it('still resets with a good token', function () {
        $user = bbUser();
        $token = Password::createToken($user);

        $this->postJson('/auth/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'new-horse-battery'])
            ->assertOk();
        expect(Hash::check('new-horse-battery', $user->fresh()->password))->toBeTrue();
    });

    it('spends hash work on an unknown address too (no early return)', function () {
        // The real hasher, counting check() calls.
        $hasher = new class(app()) extends HashManager
        {
            public int $checks = 0;

            public function check($value, $hashedValue, array $options = [])
            {
                $this->checks++;

                return parent::check($value, $hashedValue, $options);
            }
        };
        Hash::swap($hasher);

        $this->postJson('/auth/reset-password', ['token' => 'whatever', 'email' => 'nobody@example.test', 'password' => 'new-horse-battery'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', RESET_FAILED);
        expect($hasher->checks)->toBeGreaterThan(0);
    });

    it('keeps forgot-password generic', function () {
        $this->postJson('/auth/forgot-password', ['email' => 'nobody@example.test'])
            ->assertOk()
            ->assertJsonPath('message', 'If that address has an account, a link is on its way.');
    });
});

describe('F10 a throttled login says how long to wait', function () {
    it('replaces "Too Many Attempts." with the seconds left', function () {
        bbUser();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/auth/login', ['email' => 'rani@example.test', 'password' => 'wrong-'.$i]);
        }

        $response = $this->postJson('/auth/login', ['email' => 'rani@example.test', 'password' => 'wrong-again'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        $seconds = (int) $response->headers->get('Retry-After');
        expect($seconds)->toBeGreaterThan(0)
            ->and($response->json('message'))->toBe("Too many attempts. Try again in {$seconds} seconds.")
            ->and($response->json('retry_after'))->toBe($seconds)
            ->and((string) $response->getContent())->not->toContain('Too Many Attempts.');
    });
});

describe('F8 the cheap endpoints stay cheap', function () {
    it('/up answers without a session, cookies or a database query', function () {
        DB::enableQueryLog();

        $response = $this->get('/up')->assertOk()->assertJson(['status' => 'ok']);

        expect(DB::getQueryLog())->toBe([])
            ->and($response->headers->getCookies())->toBe([]);
    });

    it('/api/v1/plans is one query', function () {
        DB::enableQueryLog();

        $this->getJson('/api/v1/plans')->assertOk();

        expect(count(DB::getQueryLog()))->toBe(1);
    });
});

describe('F9 OAuth metadata points at the MCP docs', function () {
    it('service_documentation and resource_documentation are https://creatif.space/docs/mcp', function () {
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('service_documentation', 'https://creatif.space/docs/mcp');
        $this->getJson('/.well-known/oauth-protected-resource')
            ->assertOk()
            ->assertJsonPath('resource_documentation', 'https://creatif.space/docs/mcp');
    });
});
