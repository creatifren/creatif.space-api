<?php

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Credits;

function allowancePost(User $user, int $targets): SocialPost
{
    $post = SocialPost::factory()->for($user)->create();
    foreach (range(1, $targets) as $i) {
        $post->targets()->create(['social_account_id' => SocialAccount::factory()->for($user)->create()->id, 'status' => 'pending']);
    }

    return $post;
}

it('uses the allowance first and only charges Credits for the overflow', function () {
    $user = User::factory()->create();
    Credits::grant($user, 3000, 'Welcome');
    $post = allowancePost($user, 12);
    Credits::chargeSend($post);
    expect(Credits::balance($user))->toBe(2400)
        ->and(Credits::allowanceUsed($user))->toBe(10)
        ->and($post->targets()->where('paid_with', 'credits')->count())->toBe(2);
    Credits::chargeSend($post); // same targets never charge twice
    expect(Credits::balance($user))->toBe(2400);
});

it('returns a failed publish to the original source, not all to Credits', function () {
    $user = User::factory()->create();
    Credits::grant($user, 3000, 'Welcome');
    $post = allowancePost($user, 11);
    Credits::chargeSend($post);
    $free = $post->targets()->where('paid_with', 'allowance')->first();
    Credits::returnSend($post, [$free], 'Failed');
    expect(Credits::balance($user))->toBe(2700)->and(Credits::allowanceUsed($user))->toBe(9);
    $paid = $post->targets()->where('paid_with', 'credits')->first();
    Credits::returnSend($post, [$paid], 'Failed');
    expect(Credits::balance($user))->toBe(3000);
});

it('resets on the original anniversary after short months without rollover', function () {
    $this->travelTo(now()->setDate(2026, 3, 30)->startOfDay());
    $user = User::factory()->create(['created_at' => now()->setDate(2026, 1, 31)]);
    expect(Credits::allowanceSince($user)->toDateString())->toBe('2026-02-28');
    $this->travelTo(now()->setDate(2026, 3, 31));
    expect(Credits::allowanceSince($user)->toDateString())->toBe('2026-03-31');
});

it('keeps yearly plan allowance monthly', function () {
    $this->travelTo(now()->setDate(2026, 10, 31));
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create([
        'billing_period' => 'yearly', 'current_period_start' => '2026-01-15', 'current_period_end' => '2027-01-15',
    ]);
    expect(Credits::allowanceSince($user)->toDateString())->toBe('2026-10-15');
});
