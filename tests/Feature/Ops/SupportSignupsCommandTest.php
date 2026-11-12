<?php

use App\Enums\SocialPostTargetStatus;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/*
 * php artisan support:signups {--days=7} — new accounts per day and how far
 * they got, read-only, counts only.
 */

/** A UTC moment, written in the app's zone (the column keeps wall-clock time only). */
function suAt(string $utc): Carbon
{
    return Carbon::parse($utc, 'UTC')->setTimezone(config('app.timezone'));
}

function suRun(array $args = []): array
{
    $code = Artisan::call('support:signups', $args);

    return [$code, Artisan::output()];
}

beforeEach(function () {
    $this->travelTo(suAt('2026-11-12 10:00:00'));
});

it('counts new non-internal accounts per day and the milestones each reached', function () {
    // 11 Nov: one that went all the way, one that only connected a channel.
    $full = User::factory()->create([
        'email' => 'full-secret@example.test', 'name' => 'Rani Secret', 'created_at' => suAt('2026-11-11 08:00:00'),
        'signup_attribution' => ['utm_source' => 'instagram', 'signup_source' => 'instagram'],
    ]);
    $account = SocialAccount::factory()->for($full)->create(['platform' => 'instagram']);
    $post = SocialPost::factory()->for($full)->create();
    SocialApprovalLink::create(['user_id' => $full->id]);
    SocialPostTarget::query()->create([
        'social_post_id' => $post->id, 'social_account_id' => $account->id, 'status' => SocialPostTargetStatus::Published,
    ]);

    $channelOnly = User::factory()->create(['email' => 'half@example.test', 'created_at' => suAt('2026-11-11 23:30:00')]);
    SocialAccount::factory()->for($channelOnly)->create();

    // 12 Nov: one with nothing yet. Internal accounts are never counted.
    User::factory()->create(['email' => 'new@example.test', 'created_at' => suAt('2026-11-12 01:00:00'), 'signup_attribution' => ['signup_source' => 'referral']]);
    User::factory()->create(['email' => 'hello@creatif.space', 'created_at' => suAt('2026-11-12 02:00:00')]);
    User::factory()->create(['email' => 'ops@example.test', 'is_staff' => true, 'created_at' => suAt('2026-11-12 03:00:00')]);

    // Older than the window.
    User::factory()->create(['email' => 'old@example.test', 'created_at' => suAt('2026-10-01 08:00:00')]);

    [$code, $out] = suRun();

    expect($code)->toBe(0)
        ->and($out)->toMatch('/2026-11-12\s*\|\s*1\s*\|\s*0 \(0%\)\s*\|\s*0 \(0%\)\s*\|\s*0 \(0%\)\s*\|\s*0 \(0%\)\s*\|/')
        ->toMatch('/2026-11-11\s*\|\s*2\s*\|\s*2 \(100%\)\s*\|\s*1 \(50%\)\s*\|\s*1 \(50%\)\s*\|\s*1 \(50%\)\s*\|/')
        ->toMatch('/2026-11-06\s*\|\s*0\s*\|\s*0\s*\|/')
        ->toMatch('/Total\s*\|\s*3\s*\|\s*2 \(67%\)\s*\|\s*1 \(33%\)\s*\|\s*1 \(33%\)\s*\|\s*1 \(33%\)\s*\|/')
        ->toMatch('/instagram\s*\|\s*1\s*\|\s*1 \(100%\)/')
        ->toMatch('/referral\s*\|\s*1\s*\|\s*0 \(0%\)/')
        ->toMatch('/unknown\s*\|\s*1\s*\|\s*0 \(0%\)/')
        ->toContain('Internal accounts left out: 2.')
        ->not->toContain('2026-11-05')
        ->not->toContain('2026-10-01');

    // Counts only: never an address, a name or an id.
    expect($out)->not->toContain('@')
        ->not->toContain('Secret')
        ->not->toContain($full->ulid);
});

it('takes --days and says when there is nothing', function () {
    [$code, $out] = suRun(['--days' => 1]);

    expect($code)->toBe(0)
        ->and($out)->toContain('last 1 day(s)')
        ->toContain('2026-11-12')
        ->not->toContain('2026-11-11')
        ->toContain('No sign-ups in this period.')
        ->toContain('Internal accounts left out: 0.');
});

it('refuses a --days out of range', function (int $days) {
    [$code, $out] = suRun(['--days' => $days]);

    expect($code)->toBe(1)->and($out)->toContain('--days must be between 1 and 90.');
})->with([0, 91]);

it('changes nothing', function () {
    $user = User::factory()->create(['created_at' => suAt('2026-11-12 01:00:00')]);
    $before = $user->fresh()->toArray();

    suRun();

    expect($user->fresh()->toArray())->toBe($before)
        ->and(User::query()->count())->toBe(1);
});
