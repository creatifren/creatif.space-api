<?php

use App\Enums\UserStatus;
use App\Jobs\SendConnectNudges;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\ConnectChannelNudge;
use App\Notifications\WelcomeEmail;
use App\Support\Onboarding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;

/*
 * The owner's onboarding emails: one welcome email (connect a first channel,
 * connect to Claude; slogan footer, no provider named), and one day-2
 * reminder when no channel is connected (Settings → Notifications →
 * Product news, once ever).
 */

beforeEach(function () {
    config(['app.frontend_url' => 'https://app.creatif.space', 'app.name' => 'Creatif Space']);
    Notification::fake();
});

/** Socialite answers the Google callback with this person. */
function obGoogle(string $email = 'rani@example.com', string $id = 'g-ob-1'): void
{
    $google = (new GoogleUser)->map(['id' => $id, 'name' => 'Rani', 'email' => $email, 'avatar' => null]);
    $driver = Mockery::mock(GoogleProvider::class);
    $driver->shouldReceive('user')->andReturn($google);
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
}

function obVerifyUrl(User $user): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->getKey(),
        'hash' => sha1($user->email),
    ]);
}

/** A creator who got the welcome $hours ago and has no channel. */
function obCreator(int $hours, array $attributes = []): User
{
    return User::factory()->create([
        'created_at' => now()->subHours($hours),
        'welcome_sent_at' => now()->subHours($hours),
        'email_verified_at' => now()->subHours($hours),
        ...$attributes,
    ]);
}

/** Visible text of a rendered mail, tags stripped, whitespace collapsed. */
function obText(string $html): string
{
    return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
}

describe('welcome email', function () {
    it('goes once to a new Google account, not again on the next sign-in', function () {
        obGoogle();
        $this->get('/auth/google/callback?code=abc')->assertRedirect();
        auth()->logout();
        $this->get('/auth/google/callback?code=abc')->assertRedirect();

        $user = User::query()->sole();
        Notification::assertSentToTimes($user, WelcomeEmail::class, 1);
        expect($user->fresh()->welcome_sent_at)->not->toBeNull();
    });

    it('is not sent to a client who signed in with Google to answer an approval or a plan', function (string $next) {
        $this->get('/auth/google/redirect?next='.urlencode($next));
        obGoogle('client@example.com', 'g-client');
        $this->get('/auth/google/callback?code=abc')->assertRedirect();

        Notification::assertNotSentTo(User::query()->sole(), WelcomeEmail::class);
    })->with(['/a/tok_123?post=01J', '/plan/tok_456']);

    it('goes to an email signup once the address is verified, not at register', function () {
        $this->postJson('/auth/register', ['email' => 'new@example.test', 'password' => 'correct-horse'])->assertStatus(202);
        $user = User::query()->firstWhere('email', 'new@example.test');
        Notification::assertNotSentTo($user, WelcomeEmail::class);

        $this->get(obVerifyUrl($user))->assertRedirect();

        Notification::assertSentToTimes($user, WelcomeEmail::class, 1);
    });

    it('is not sent to an existing account that links Google', function () {
        $user = User::factory()->create(['email' => 'rani@example.com', 'google_id' => null]);
        obGoogle();

        $this->get('/auth/google/callback?code=abc')->assertRedirect();

        Notification::assertNotSentTo($user, WelcomeEmail::class);
    });

    it('is sent once even when asked twice', function () {
        $user = User::factory()->create();

        Onboarding::welcome($user);
        Onboarding::welcome($user->fresh());

        Notification::assertSentToTimes($user, WelcomeEmail::class, 1);
    });

    it('links to the first channel and to Claude, with the slogan footer and no provider named', function () {
        $user = User::factory()->make(['name' => 'Rani']);
        $mail = (new WelcomeEmail)->toMail($user);
        $html = (string) $mail->render();
        $text = obText($html);

        expect((new WelcomeEmail)->via($user))->toBe(['mail'])
            ->and($mail->subject)->toBe('Welcome to Creatif Space')
            ->and($html)->toContain('href="https://app.creatif.space/post/channels"')
            ->toContain('href="https://app.creatif.space/connect/claude"')
            ->toContain('class="approval-footer"')
            ->toContain('class="approval-slogan"')
            ->not->toContain('class="approval-link"')
            ->and($text)->toContain('Connect your first channel')
            ->toContain('Connect to Claude')
            ->not->toContain('Approved with Creatif Space');

        foreach (['Outstand', 'Post for Me', 'PostForMe', 'Resend', 'OpenAI', 'Hostinger', 'Cloudflare', 'Capy'] as $vendor) {
            expect($text)->not->toContain($vendor);
        }
    });
});

describe('day-2 connect nudge', function () {
    it('goes once, 24–72 hours after signing up, while no channel is connected', function () {
        $due = obCreator(25);

        (new SendConnectNudges)->handle();
        (new SendConnectNudges)->handle();

        Notification::assertSentToTimes($due, ConnectChannelNudge::class, 1);
        expect($due->fresh()->connect_nudge_sent_at)->not->toBeNull();
    });

    it('skips everyone it should', function () {
        $tooSoon = obCreator(23);
        $tooOld = obCreator(80);
        $withChannel = obCreator(30);
        SocialAccount::factory()->for($withChannel)->create();
        $noWelcome = obCreator(30, ['welcome_sent_at' => null]);
        $optedOut = obCreator(30);
        $optedOut->notificationPreferences()->create(['type' => 'product.news', 'email_enabled' => false, 'bell_enabled' => true]);
        $internal = obCreator(30, ['email' => 'hello@creatif.space']);
        $staff = obCreator(30, ['is_staff' => true]);
        $suspended = obCreator(30, ['status' => UserStatus::Suspended]);
        $already = obCreator(30, ['connect_nudge_sent_at' => now()->subHour()]);

        (new SendConnectNudges)->handle();

        foreach ([$tooSoon, $tooOld, $withChannel, $noWelcome, $optedOut, $internal, $staff, $suspended, $already] as $user) {
            Notification::assertNotSentTo($user, ConnectChannelNudge::class);
        }
    });

    it('is sent once even when two runs overlap', function () {
        $user = obCreator(26);

        expect(Onboarding::nudge($user))->toBeTrue()
            ->and(Onboarding::nudge($user->fresh()))->toBeFalse();

        Notification::assertSentToTimes($user, ConnectChannelNudge::class, 1);
    });

    it('checks the Product news setting again when it is sent', function () {
        $user = obCreator(26);
        $user->notificationPreferences()->create(['type' => 'product.news', 'email_enabled' => false, 'bell_enabled' => true]);

        expect((new ConnectChannelNudge)->via($user->fresh()->load('notificationPreferences')))->toBe([])
            ->and((new ConnectChannelNudge)->via(obCreator(26)->load('notificationPreferences')))->toBe(['mail']);
    });

    it('says what to do, links to Channels and Claude, with the slogan footer and no provider named', function () {
        $user = User::factory()->make(['name' => 'Rani']);
        $mail = (new ConnectChannelNudge)->toMail($user);
        $html = (string) $mail->render();
        $text = obText($html);

        expect($mail->subject)->toBe('Connect your first channel')
            ->and($html)->toContain('href="https://app.creatif.space/post/channels"')
            ->toContain('href="https://app.creatif.space/connect/claude"')
            ->toContain('class="approval-slogan"')
            ->not->toContain('class="approval-link"')
            ->and($text)->toContain('Settings → Notifications → Product news');

        foreach (['Outstand', 'Post for Me', 'Resend', 'OpenAI', 'Capy'] as $vendor) {
            expect($text)->not->toContain($vendor);
        }
    });

    it('runs hourly from the scheduler', function () {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->description, SendConnectNudges::class));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('0 * * * *');
    });
});

it('keeps the approval emails\' footer link (the slogan-only footer is the onboarding emails\' alone)', function () {
    $mail = \App\Notifications\ApprovalMailFooter::on(new \Illuminate\Notifications\Messages\MailMessage);
    $html = (string) $mail->line('x')->render();

    expect($html)->toContain('class="approval-link"')
        ->toContain('utm_source=approval-footer');
});
