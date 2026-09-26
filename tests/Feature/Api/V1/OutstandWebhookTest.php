<?php

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;

beforeEach(function () {
    config(['services.outstand.webhook_secret' => 'whsec_test']);
});

function outstand_webhook($test, array $payload, string $secret = 'whsec_test')
{
    $body = json_encode($payload);

    return $test->call('POST', '/api/v1/webhooks/outstand', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_OUTSTAND_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, $secret),
    ], $body);
}

/** A scheduled post with one pending target on provider post `$providerPostId`. */
function pendingTarget(string $providerPostId): array
{
    $user = User::factory()->create();
    $account = SocialAccount::factory()->for($user)->create();
    $post = SocialPost::factory()->for($user)->create();
    $target = $post->targets()->create([
        'social_account_id' => $account->id,
        'provider_post_id' => $providerPostId,
    ]);

    return [$account, $post, $target];
}

describe('the outstand webhook', function () {
    it('rejects a missing or wrong signature, and everything when no secret is set', function () {
        $this->postJson('/api/v1/webhooks/outstand', ['event' => 'test'])->assertForbidden();

        outstand_webhook($this, ['event' => 'test'], 'wrong')->assertForbidden();

        config(['services.outstand.webhook_secret' => '']);
        outstand_webhook($this, ['event' => 'test'], '')->assertForbidden();
    });

    it('records a published result and publishes the post', function () {
        [$account, $post, $target] = pendingTarget('post_ok');

        outstand_webhook($this, [
            'event' => 'post.published',
            'data' => [
                'postId' => 'post_ok',
                'socialAccounts' => [[
                    'accountId' => $account->provider_account_id,
                    'network' => 'instagram',
                    'platformPostId' => '1789',
                    'platformPostUrl' => 'https://instagram.com/p/1',
                ]],
            ],
        ])->assertOk();

        expect($target->fresh())
            ->status->value->toBe('published')
            ->platform_url->toBe('https://instagram.com/p/1')
            ->and($post->fresh()->status->value)->toBe('published');
    });

    it('records an error; all targets failed fails the post, redelivery is stable', function () {
        [$account, $post, $target] = pendingTarget('post_bad');

        $payload = [
            'event' => 'post.error',
            'data' => [
                'postId' => 'post_bad',
                'socialAccounts' => [[
                    'accountId' => $account->provider_account_id,
                    'error' => 'Video too long.',
                ]],
            ],
        ];

        outstand_webhook($this, $payload)->assertOk();

        expect($target->fresh())
            ->status->value->toBe('failed')
            ->fail_reason->toBe('Video too long.')
            ->and($post->fresh())
            ->status->value->toBe('failed')
            ->fail_reason->toBe('Video too long.');

        outstand_webhook($this, $payload)->assertOk();
        expect($post->fresh()->status->value)->toBe('failed');
    });

    it('expires an account whose token lapsed (numeric accountId)', function () {
        $account = SocialAccount::factory()->create(['provider_account_id' => '42']);

        outstand_webhook($this, [
            'event' => 'account.token_expired',
            'data' => ['accountId' => 42, 'network' => 'instagram'],
        ])->assertOk();

        expect($account->fresh()->status->value)->toBe('expired');
    });

    it('answers 200 to unknown events and unknown ids', function () {
        outstand_webhook($this, ['event' => 'message.received', 'data' => []])->assertOk();

        outstand_webhook($this, [
            'event' => 'post.published',
            'data' => ['postId' => 'nobody', 'socialAccounts' => [['accountId' => 'x']]],
        ])->assertOk();
    });
});
