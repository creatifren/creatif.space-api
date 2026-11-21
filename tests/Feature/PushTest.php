<?php

use App\Enums\NotificationType;
use App\Models\DeviceToken;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\User;
use App\Notifications\Channels\PushChannel;
use App\Notifications\SocialPostDecided;
use App\Notifications\SocialPostFailed;
use App\Support\Fcm;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/* Push (FCM), spec android-share-push §2 (Rizal yes 21 Nov): the Android
   app registers the phone, three events push (approved, changes requested,
   post failed), push follows the bell switch + one "push" switch, and is
   off without FCM_CREDENTIALS. */

function pushKey(): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);

    return json_encode(['project_id' => 'creatif-space-test', 'client_email' => 'fcm@creatif-space.test', 'private_key' => $pem]);
}

function pushOn(): void
{
    config(['services.fcm.credentials' => base64_encode(pushKey())]);
    Cache::flush();
}

function pushUser(): User
{
    $user = User::factory()->create();
    $user->deviceTokens()->create(['token' => 'fcm-'.$user->id.'-'.str_repeat('a', 36), 'platform' => 'android', 'last_seen_at' => now()]);

    return $user;
}

describe('POST /devices', function () {
    it('registers a phone, and the same token moves to whoever signed in last', function () {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $token = 'fcm-token-'.str_repeat('x', 30);

        $this->actingAs($a)->postJson('/api/v1/devices', ['token' => $token, 'app_version' => '1.4.0'])
            ->assertCreated()->assertJsonPath('data.registered', true);
        $this->actingAs($b)->postJson('/api/v1/devices', ['token' => $token])->assertOk();

        expect(DeviceToken::query()->count())->toBe(1)
            ->and(DeviceToken::query()->first()->user_id)->toBe($b->id)
            ->and(DeviceToken::query()->first()->app_version)->toBe('1.4.0');
    });

    it('needs a token and a signed-in user', function () {
        $this->postJson('/api/v1/devices', ['token' => str_repeat('a', 40)])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson('/api/v1/devices', [])->assertUnprocessable()->assertJsonValidationErrors('token');
    });

    it('DELETE forgets only the signed-in person\'s phone (another\'s: 404)', function () {
        $a = pushUser();
        $b = User::factory()->create();
        $token = $a->deviceTokens()->value('token');
        $this->actingAs($b)->deleteJson('/api/v1/devices/'.$token)->assertNotFound();
        expect($a->deviceTokens()->count())->toBe(1);
        $this->actingAs($a)->deleteJson('/api/v1/devices/'.$token)->assertNoContent();
        expect($a->deviceTokens()->count())->toBe(0);
    });
});

describe('the push switch', function () {
    it('is on by default and turns off from notification settings', function () {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/v1/me/notification-settings')->assertJsonPath('data.push', true);
        $this->actingAs($user)->patchJson('/api/v1/me/notification-settings', ['push' => false])->assertOk()->assertJsonPath('data.push', false);
        expect($user->fresh()->push_enabled)->toBeFalse();
    });
});

describe('PushChannel', function () {
    it('is off without FCM_CREDENTIALS, without a phone, or with the switch off', function () {
        config(['services.fcm.credentials' => null]);
        expect(PushChannel::when(pushUser()))->toBe([]);

        pushOn();
        expect(PushChannel::when(User::factory()->create()))->toBe([])
            ->and(PushChannel::when(pushUser()))->toBe([PushChannel::class]);
        $off = pushUser();
        $off->forceFill(['push_enabled' => false])->save();
        expect(PushChannel::when($off))->toBe([]);
    });

    it('reads the key as a file path, raw JSON or base64', function () {
        $json = pushKey();
        $path = tempnam(sys_get_temp_dir(), 'fcm');
        file_put_contents($path, $json);
        foreach ([$path, $json, base64_encode($json)] as $value) {
            config(['services.fcm.credentials' => $value]);
            expect(Fcm::credentials()['project_id'] ?? null)->toBe('creatif-space-test');
        }
        config(['services.fcm.credentials' => 'not a key']);
        expect(Fcm::configured())->toBeFalse();
    });

    it('a failed post pushes "tap to retry" with the post page as the url', function () {
        pushOn();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/x/messages/1']),
        ]);
        $user = pushUser();
        $post = SocialPost::factory()->for($user)->create();

        $user->notify(new SocialPostFailed($post));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'fcm.googleapis.com/v1/projects/creatif-space-test/messages:send')
            && $r['message']['token'] === $user->deviceTokens()->value('token')
            && $r['message']['notification']['title'] === 'A post didn’t publish'
            && $r['message']['data']['url'] === '/post/'.$post->ulid);
    });

    it('approved / changes requested push; the bell switch off silences the push too', function () {
        pushOn();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test']),
            'fcm.googleapis.com/*' => Http::response(['name' => 'ok']),
        ]);
        $user = pushUser();
        $post = SocialPost::factory()->for($user)->create();
        $link = new SocialApprovalLink;
        $link->ulid = '01JPUSHTESTLINK00000000000';
        $link->title = 'Week 48';
        $link->setRelation('user', $user);

        $approved = new SocialPostDecided($link, SocialPostDecided::APPROVED, 'Dewi', 3);
        expect($approved->via($user))->toContain(PushChannel::class)
            ->and($approved->toPush($user))->toBe(['title' => 'Approved', 'body' => 'Dewi approved 3 posts', 'url' => '/post?view=approvals'])
            ->and((new SocialPostDecided($link, SocialPostDecided::REVISE, 'Dewi', 1, $post))->toPush($user)['url'])->toBe('/post/'.$post->ulid);

        $user->notificationPreferences()->create(['type' => NotificationType::SocialPostDecision->value, 'email_enabled' => true, 'bell_enabled' => false]);
        expect((new SocialPostDecided($link, SocialPostDecided::APPROVED, 'Dewi'))->via($user->fresh()))->not->toContain(PushChannel::class);
    });

    it('a phone FCM no longer knows (UNREGISTERED) is forgotten', function () {
        pushOn();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test']),
            'fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
        ]);
        $user = pushUser();
        $user->notify(new SocialPostFailed(SocialPost::factory()->for($user)->create()));

        expect($user->deviceTokens()->count())->toBe(0);
    });
});
