<?php

use App\Http\Controllers\Api\V1\FeedbackController;
use App\Models\Feedback;
use App\Models\User;
use App\Notifications\FeedbackReceived;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/*
 * Tester feedback — POST /api/v1/feedback and MCP send_feedback: stored,
 * one email to hello@creatif.space with reply-to the user, 10 an hour
 * per user, internal accounts flagged.
 */

beforeEach(function () {
    Notification::fake();
    ensurePassportKeys();
});

function fbUser(array $attrs = []): User
{
    return User::query()->findOrFail(User::factory()->create($attrs + ['name' => 'Rani', 'email' => 'rani@client.test'])->id);
}

it('stores the feedback and emails the team once, reply-to the user', function () {
    $user = fbUser();

    $res = $this->actingAs($user)->postJson('/api/v1/feedback', [
        'kind' => 'bug', 'message' => '  The calendar shows my post a day late  ', 'page' => 'Content Plan → Calendar',
    ])->assertCreated()->assertJsonPath('data.kind', 'bug');

    $row = Feedback::query()->where('ulid', $res->json('data.id'))->firstOrFail();
    expect($row->only(['user_id', 'kind', 'message', 'page', 'source', 'internal']))->toBe([
        'user_id' => $user->id, 'kind' => 'bug', 'message' => 'The calendar shows my post a day late',
        'page' => 'Content Plan → Calendar', 'source' => 'web', 'internal' => false,
    ]);

    Notification::assertSentOnDemandTimes(FeedbackReceived::class, 1);
    Notification::assertSentOnDemand(FeedbackReceived::class, function (FeedbackReceived $n, array $channels, AnonymousNotifiable $to) use ($user) {
        $mail = $n->toMail($to);

        return $to->routes['mail'] === FeedbackController::INBOX
            && $mail->replyTo === [[$user->email, $user->name]]
            && str_starts_with($mail->subject, 'Feedback (bug): The calendar shows my post')
            && in_array('The calendar shows my post a day late', $mail->introLines, true);
    });
});

it('flags internal accounts in the row and the subject', function () {
    $staff = fbUser(['email' => 'ops@creatif.test', 'is_staff' => true]);

    $this->actingAs($staff)->postJson('/api/v1/feedback', ['kind' => 'idea', 'message' => 'Test from staff'])->assertCreated();

    expect(Feedback::query()->sole()->internal)->toBeTrue();
    Notification::assertSentOnDemand(FeedbackReceived::class, fn (FeedbackReceived $n, $c, AnonymousNotifiable $to) => str_starts_with($n->toMail($to)->subject, '[internal] '));
});

it('validates message and kind', function () {
    $user = fbUser();
    $this->actingAs($user)->postJson('/api/v1/feedback', ['kind' => 'rant', 'message' => ''])
        ->assertUnprocessable()->assertJsonValidationErrors(['kind', 'message']);
    $this->actingAs($user)->postJson('/api/v1/feedback', ['kind' => 'bug', 'message' => str_repeat('a', 5001)])
        ->assertUnprocessable()->assertJsonValidationErrors(['message']);
    expect(Feedback::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('allows 10 an hour per user, then answers 429 with retry_after; others are not affected', function () {
    $user = fbUser();
    foreach (range(1, 10) as $i) {
        $this->actingAs($user)->postJson('/api/v1/feedback', ['kind' => 'other', 'message' => "Note {$i}"])->assertCreated();
    }

    $this->actingAs($user)->postJson('/api/v1/feedback', ['kind' => 'other', 'message' => 'Note 11'])
        ->assertStatus(429)
        ->assertJsonStructure(['message', 'retry_after'])
        ->assertHeader('Retry-After');
    expect(Feedback::query()->count())->toBe(10);

    $other = fbUser(['email' => 'someone@else.test']);
    $this->actingAs($other)->postJson('/api/v1/feedback', ['kind' => 'other', 'message' => 'Mine'])->assertCreated();
});

it('is 401 signed out', function () {
    $this->postJson('/api/v1/feedback', ['kind' => 'bug', 'message' => 'Hello'])->assertUnauthorized();
});

it('records source mcp from a draft token, and refuses a read-only token (send_feedback)', function () {
    $user = fbUser();
    $draft = $this->actingAs($user, 'web')->postJson('/api/v1/me/api-keys', ['name' => 'Claude', 'scopes' => ['read', 'draft']])->json('data.key');
    $read = $this->actingAs($user, 'web')->postJson('/api/v1/me/api-keys', ['name' => 'Other', 'scopes' => ['read']])->json('data.key');
    app('auth')->forgetGuards();

    bearer($this, $draft)->postJson('/api/v1/feedback', ['kind' => 'idea', 'message' => 'From Claude'])->assertCreated();
    expect(Feedback::query()->sole()->source)->toBe('mcp');

    app('auth')->forgetGuards();
    bearer($this, $read)->postJson('/api/v1/feedback', ['kind' => 'idea', 'message' => 'Nope'])->assertForbidden();
    expect(Feedback::query()->count())->toBe(1);
});
