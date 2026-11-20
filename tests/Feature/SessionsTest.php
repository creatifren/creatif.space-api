<?php

use App\Http\Controllers\Api\V1\SessionController;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/* P11 · Account & security › Signed in on. */

function sessRow(User $user, string $id, string $ua, int $ago = 0): void
{
    DB::table('sessions')->insert([
        'id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1',
        'user_agent' => $ua, 'payload' => '', 'last_activity' => now()->subMinutes($ago)->timestamp,
    ]);
}

const MAC_CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36';
const ANDROID_APP = 'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Mobile Safari/537.36 CreatifAndroid/1.2.0';

it('names devices', function () {
    expect(SessionController::device(MAC_CHROME))->toBe('Chrome · macOS')
        ->and(SessionController::device(ANDROID_APP))->toBe('Android app')
        ->and(SessionController::device('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1'))->toBe('Safari · iPhone')
        ->and(SessionController::device(''))->toBe('Browser');
});

it('lists only my sessions, newest first, without raw ids', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    sessRow($me, 'sess-a', MAC_CHROME, 120);
    sessRow($me, 'sess-b', ANDROID_APP, 1);
    sessRow($other, 'sess-c', MAC_CHROME);

    $res = $this->actingAs($me)->getJson('/api/v1/me/sessions')->assertOk();

    expect($res->json('data'))->toHaveCount(2)
        ->and($res->json('data.0.device'))->toBe('Android app')
        ->and($res->json('data.0.app'))->toBeTrue()
        ->and($res->json('data.1.device'))->toBe('Chrome · macOS')
        ->and($res->getContent())->not->toContain('sess-a');
});

it('signs one device out and turns the remember token over', function () {
    $me = User::factory()->create(['remember_token' => 'old-token']);
    sessRow($me, 'sess-a', MAC_CHROME);
    sessRow($me, 'sess-b', ANDROID_APP);

    $this->actingAs($me)->deleteJson('/api/v1/me/sessions/'.SessionController::key('sess-a'))->assertNoContent();

    expect(DB::table('sessions')->where('id', 'sess-a')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'sess-b')->exists())->toBeTrue()
        ->and($me->fresh()->remember_token)->not->toBe('old-token');
});

it('answers 404 for a device that is not mine', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    sessRow($other, 'sess-c', MAC_CHROME);

    $this->actingAs($me)->deleteJson('/api/v1/me/sessions/'.SessionController::key('sess-c'))->assertNotFound();
    expect(DB::table('sessions')->where('id', 'sess-c')->exists())->toBeTrue();
});

it('signs every other device out', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    sessRow($me, 'sess-a', MAC_CHROME);
    sessRow($me, 'sess-b', ANDROID_APP);
    sessRow($other, 'sess-c', MAC_CHROME);

    $this->actingAs($me)->deleteJson('/api/v1/me/sessions')->assertNoContent();

    expect(DB::table('sessions')->where('user_id', $me->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('id', 'sess-c')->exists())->toBeTrue();
});
