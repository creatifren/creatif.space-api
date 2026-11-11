<?php

use App\Enums\SocialPostTargetStatus;
use App\Models\File;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\Subscription;
use App\Models\TeamMember;
use App\Models\User;
use App\Support\Workspace;
use Illuminate\Support\Facades\Artisan;

/*
 * php artisan support:user {email} — launch-day support, read-only.
 */

beforeEach(function () {
    Workspace::forget();
});

function supportOutput(string $email): array
{
    $code = Artisan::call('support:user', ['email' => $email]);

    return [$code, Artisan::output()];
}

it('prints plan, platform, channels, queue, storage and the last 5 failures with reasons', function () {
    $user = User::factory()->create(['email' => 'rani@example.test']);
    Subscription::factory()->for($user)->create(['provider' => 'google', 'google_purchase_token' => 'play-token-SECRET-123']);
    $ig = SocialAccount::factory()->for($user)->create(['platform' => 'instagram', 'username' => 'rani', 'provider' => SocialAccount::currentProvider()]);
    SocialAccount::factory()->for($user)->create(['platform' => 'tiktok', 'username' => 'old', 'provider' => SocialAccount::currentProvider(), 'status' => 'disconnected']);
    SocialPost::factory()->for($user)->count(2)->create(['status' => 'scheduled', 'scheduled_at' => now()->addDay()]);
    File::factory()->for($user)->create(['size_bytes' => 3 * 1024 * 1024, 'status' => File::STATUS_READY]);

    foreach (range(1, 7) as $i) {
        $post = SocialPost::factory()->for($user)->create(['status' => 'failed', 'scheduled_at' => null]);
        $target = SocialPostTarget::query()->create([
            'social_post_id' => $post->id, 'social_account_id' => $ig->id,
            'status' => SocialPostTargetStatus::Failed,
            'fail_code' => 'media_rejected',
            'fail_reason' => "Instagram rejected the photo (#{$i}).",
            'provider_error' => 'raw relay text with access_token=EAAB-SECRET',
        ]);
        $target->forceFill(['updated_at' => now()->subHours(10 - $i)])->save();
    }

    [$code, $out] = supportOutput('RANI@example.test');

    expect($code)->toBe(0)
        ->and($out)->toContain('rani@example.test')
        ->toContain('Pro (pro)')
        ->toContain('android (Google Play billing)')
        ->toContain('1 connected, 1 need reconnecting (instagram @rani)')
        ->toContain('2 (limit none)')
        ->toContain('3.0 MB of')
        ->toContain('Last 5 publish failures')
        ->toContain('media_rejected')
        ->toContain('Instagram rejected the photo (#7).')
        ->toContain('Instagram rejected the photo (#3).')
        ->not->toContain('(#2).')
        ->not->toContain('SECRET')
        ->not->toContain('access_token');
});

it('shows a teammate the owner\'s plan and workspace', function () {
    $owner = User::factory()->create(['email' => 'owner@example.test']);
    Subscription::factory()->for($owner)->create(['seats' => 2, 'provider' => 'creem']);
    $member = User::factory()->create(['email' => 'member@example.test']);
    TeamMember::factory()->active($member)->create(['owner_id' => $owner->id]);

    [$code, $out] = supportOutput('member@example.test');

    expect($code)->toBe(0)
        ->and($out)->toContain('member of owner@example.test (editor)')
        ->toContain('web (Creem billing)')
        ->toContain('Pro (pro)');
});

it('says Free with no subscription, no channels and no failures', function () {
    User::factory()->create(['email' => 'new@example.test']);

    [$code, $out] = supportOutput('new@example.test');

    expect($code)->toBe(0)
        ->and($out)->toContain('Free (free) — no subscription')
        ->toContain('none (no paid plan)')
        ->toContain('0 connected')
        ->toContain('(limit 10)')
        ->toContain('None.');
});

it('fails with a message for an unknown email', function () {
    [$code, $out] = supportOutput('nobody@example.test');

    expect($code)->toBe(1)->and($out)->toContain('No account with the email nobody@example.test.');
});
