<?php

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;

beforeEach(function () {
    config(['services.postforme.webhook_secret' => 'whsec_test']);
});

function postforme_webhook($test, array $payload, string $secret = 'whsec_test')
{
    return $test->postJson('/api/v1/webhooks/postforme', $payload, [
        'Post-For-Me-Webhook-Secret' => $secret,
    ]);
}

describe('the postforme webhook', function () {
    it('rejects a missing or wrong secret', function () {
        $this->postJson('/api/v1/webhooks/postforme', ['event_type' => 'social.account.created'])
            ->assertForbidden();

        postforme_webhook($this, ['event_type' => 'social.account.created'], 'wrong')
            ->assertForbidden();
    });

    it('creates an account for a known external_id and ignores unknown ones', function () {
        $user = User::factory()->create();

        postforme_webhook($this, [
            'event_type' => 'social.account.created',
            'data' => [
                'id' => 'acc_hook',
                'platform' => 'threads',
                'username' => '@rani',
                'status' => 'connected',
                'external_id' => $user->ulid,
            ],
        ])->assertOk();

        expect($user->socialAccounts()->where('provider_account_id', 'acc_hook')->first())
            ->not->toBeNull();

        postforme_webhook($this, [
            'event_type' => 'social.account.created',
            'data' => [
                'id' => 'acc_stranger',
                'platform' => 'threads',
                'external_id' => 'not-a-user',
            ],
        ])->assertOk();

        expect(SocialAccount::query()->where('provider_account_id', 'acc_stranger')->exists())
            ->toBeFalse();
    });

    it('records a successful result and publishes the post', function () {
        $user = User::factory()->create();
        $account = SocialAccount::factory()->for($user)->create();
        $post = SocialPost::factory()->for($user)->create(['provider_post_id' => 'post_ok']);
        $target = $post->targets()->create(['social_account_id' => $account->id]);

        postforme_webhook($this, [
            'event_type' => 'social.post.result.created',
            'data' => [
                'id' => 'res_1',
                'post_id' => 'post_ok',
                'social_account_id' => $account->provider_account_id,
                'success' => true,
                'platform_data' => ['url' => 'https://threads.net/p/1'],
            ],
        ])->assertOk();

        expect($target->fresh())
            ->status->value->toBe('published')
            ->platform_url->toBe('https://threads.net/p/1')
            ->and($post->fresh()->status->value)->toBe('published');
    });

    it('records a failure; all targets failed fails the post', function () {
        $user = User::factory()->create();
        $account = SocialAccount::factory()->for($user)->create();
        $post = SocialPost::factory()->for($user)->create(['provider_post_id' => 'post_bad']);
        $target = $post->targets()->create(['social_account_id' => $account->id]);

        $payload = [
            'event_type' => 'social.post.result.created',
            'data' => [
                'id' => 'res_2',
                'post_id' => 'post_bad',
                'social_account_id' => $account->provider_account_id,
                'success' => false,
                'error' => 'Video is 2:41 and X allows 2:20.',
            ],
        ];

        postforme_webhook($this, $payload)->assertOk();

        expect($target->fresh())
            ->status->value->toBe('failed')
            ->fail_reason->toBe('Video is 2:41 and X allows 2:20.')
            ->and($post->fresh())
            ->status->value->toBe('failed')
            ->fail_reason->toBe('Video is 2:41 and X allows 2:20.');

        // Redelivery lands on the same stable state.
        postforme_webhook($this, $payload)->assertOk();
        expect($post->fresh()->status->value)->toBe('failed');
    });

    it('publishes a post on processed and stores the raw payload', function () {
        $user = User::factory()->create();
        $post = SocialPost::factory()->for($user)->create(['provider_post_id' => 'post_done']);

        postforme_webhook($this, [
            'event_type' => 'social.post.updated',
            'data' => ['id' => 'post_done', 'status' => 'processed'],
        ])->assertOk();

        expect($post->fresh())
            ->status->value->toBe('published')
            ->raw_payload->toBe(['id' => 'post_done', 'status' => 'processed']);
    });

    it('answers 200 to unknown events', function () {
        postforme_webhook($this, ['event_type' => 'social.mystery', 'data' => []])
            ->assertOk();
    });
});
