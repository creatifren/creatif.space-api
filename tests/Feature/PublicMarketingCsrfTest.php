<?php

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/*
 * creatif.space is in SANCTUM_STATEFUL_DOMAINS (the signed-in approver), so
 * a browser POST from the marketing site is a stateful SPA request. Its
 * public, unauthenticated forms never fetch an XSRF cookie: they must not
 * answer 419 (enterprise leads were lost). Authenticated stateful routes
 * keep the CSRF check.
 *
 * Laravel skips CSRF in unit tests; this binds a validator that does not.
 */

class StrictCsrfForTest extends ValidateCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}

beforeEach(function () {
    config([
        'app.marketing_url' => 'https://creatif.space',
        'app.frontend_url' => 'https://app.creatif.space',
        'sanctum.stateful' => ['app.creatif.space', 'creatif.space'],
        'sanctum.middleware.validate_csrf_token' => StrictCsrfForTest::class,
        'services.enterprise.contact_email' => 'sales@example.com',
    ]);
    Cache::flush();
    Notification::fake();
});

/** A browser POST from the marketing site: its Origin and Referer, no XSRF cookie or header. */
function fromMarketing($test)
{
    return $test->withHeaders([
        'Origin' => 'https://creatif.space',
        'Referer' => 'https://creatif.space/contact-sales',
    ]);
}

it('takes the contact-sales form from creatif.space without an XSRF token', function () {
    fromMarketing($this)->postJson('/api/v1/enterprise/contact', [
        'name' => 'Rina',
        'email' => 'rina@example.com',
        'company' => 'Acme',
        'team_size' => '25',
        'message' => 'We would like Enterprise.',
    ])->assertStatus(202)->assertJsonPath('status', 'received');

    $this->assertDatabaseHas('enterprise_leads', ['email' => 'rina@example.com']);
});

it('still validates and honeypots the contact-sales form (422 / 202, never 419)', function () {
    fromMarketing($this)->postJson('/api/v1/enterprise/contact', ['name' => 'Rina'])
        ->assertStatus(422);

    fromMarketing($this)->postJson('/api/v1/enterprise/contact', ['website' => 'http://spam.test'])
        ->assertStatus(202);

    $this->assertDatabaseCount('enterprise_leads', 0);
});

it('keeps the contact-sales throttle', function () {
    foreach (range(1, 5) as $_) {
        fromMarketing($this)->postJson('/api/v1/enterprise/contact', ['name' => 'x'])->assertStatus(422);
    }

    fromMarketing($this)->postJson('/api/v1/enterprise/contact', ['name' => 'x'])->assertStatus(429);
});

it('answers the other public creatif.space POSTs without 419', function (string $uri, array $body, int $status) {
    fromMarketing($this)->postJson($uri, $body)->assertStatus($status);
})->with([
    'waitlist, bad input' => ['/api/v1/waitlist', ['list' => 'mcp'], 422],
    'referral click' => ['/api/v1/referrals/NOPE/click', [], 204],
    'space events, unknown space' => ['/api/v1/spaces/01HZZZZZZZZZZZZZZZZZZZZZZZ/events', ['type' => 'view'], 404],
    'space unlock, bad input' => ['/api/v1/profiles/nobody/spaces/none/unlock', [], 422],
]);

it('still requires CSRF on an authenticated stateful route', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->withHeaders(['Origin' => 'https://app.creatif.space', 'Referer' => 'https://app.creatif.space/'])
        ->postJson('/api/v1/feedback', ['message' => 'hi'])
        ->assertStatus(419);

    fromMarketing($this)->actingAs($user, 'web')
        ->postJson('/api/v1/feedback', ['message' => 'hi'])
        ->assertStatus(419);
});
