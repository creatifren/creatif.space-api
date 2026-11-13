<?php

use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialApprovalDecision;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\ApprovalLinkBound;
use App\Notifications\ApproverSignInLink;
use App\Support\ApproverGate;
use App\Support\Credits;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * Signed-in approver (Meeting #02, launch criterion 12 Nov): only the
 * invited email decides on /a/{token}, with a full audit trail.
 */

beforeEach(function () {
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'social_posts_monthly' => 0, 'credits_monthly' => 0]]);
    }
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
    ]);
    Http::fake(['api.postforme.test/*' => fn () => Http::response(['id' => 'post_'.Str::random(10), 'status' => 'scheduled'])]);
});

/** A paid owner with a held post on a link (approver_email given, or an old link without). */
function saLink(?string $approverEmail = 'rani@client.test'): array
{
    $owner = User::factory()->create();
    Subscription::factory()->for($owner)->create();
    Credits::grant($owner, 30_000, 'Test');

    $post = SocialPost::factory()->for($owner)->create([
        'provider_post_id' => null, 'status' => 'scheduled', 'approval' => 'client', 'scheduled_at' => now()->addDays(2),
    ]);
    $post->targets()->create(['social_account_id' => SocialAccount::factory()->for($owner)->create()->id, 'status' => 'pending']);

    $link = SocialApprovalLink::create(['user_id' => $owner->id, 'title' => 'November', 'approver_email' => $approverEmail]);
    $link->posts()->attach($post->id);

    return [$owner, $post, $link];
}

function saFree(): User
{
    $user = User::factory()->create();
    Credits::grant($user, 30_000, 'Test');

    return $user;
}

function saDraft(User $user): SocialPost
{
    return SocialPost::factory()->for($user)->create(['status' => 'draft', 'provider_post_id' => null, 'scheduled_at' => null]);
}

function saDecide($test, SocialApprovalLink $link, SocialPost $post, string $decision = 'approve', array $extra = []): TestResponse
{
    return $test->withHeaders(['User-Agent' => 'SignedInApproverTest/1.0'])
        ->postJson("/api/v1/a/{$link->token}/posts/{$post->ulid}/decision", ['decision' => $decision] + $extra);
}

describe('deciding needs the invited approver signed in', function () {
    it('lets anyone look without signing in, and says who may decide', function () {
        [, $post, $link] = saLink();

        $this->getJson("/api/v1/a/{$link->token}")
            ->assertOk()
            ->assertJsonPath('data.posts.0.id', $post->ulid)
            ->assertJsonPath('data.approver', [
                'approver_required' => true,
                'approver_hint' => 'r***@c***.test',
                'bind_on_first_decision' => false,
                'signed_in' => false,
                'signed_in_email' => null,
                'can_decide' => false,
                'sign_in_methods' => ['google', 'email'],
            ]);
    });

    it('answers 401 sign_in_required to an anonymous decision, approve-all and take-back', function () {
        [, $post, $link] = saLink();
        $expected = ['reason' => 'sign_in_required', 'methods' => ['google', 'email']];

        saDecide($this, $link, $post)->assertStatus(401)->assertJson($expected);
        $this->postJson("/api/v1/a/{$link->token}/approve-all", ['post_ids' => [$post->ulid]])->assertStatus(401)->assertJson($expected);
        $this->postJson("/api/v1/a/{$link->token}/posts/{$post->ulid}/retract")->assertStatus(401)->assertJson($expected);

        expect($post->fresh()->approval->value)->toBe('client')
            ->and(SocialApprovalDecision::query()->count())->toBe(0);
    });

    it('answers 403 approver_mismatch to another signed-in address, with a masked hint', function () {
        [, $post, $link] = saLink();

        saDecide(asApprover($this, 'someone@else.test'), $link, $post)
            ->assertStatus(403)
            ->assertJson(['reason' => 'approver_mismatch', 'expected_hint' => 'r***@c***.test'])
            ->assertJsonMissing(['expected_hint' => 'rani@client.test']);

        expect($post->fresh()->approval->value)->toBe('client');
    });

    it('refuses the right address while it is not verified', function () {
        [, $post, $link] = saLink();
        $unverified = User::factory()->create(['email' => 'rani@client.test', 'email_verified_at' => null]);

        saDecide($this->actingAs(User::findOrFail($unverified->id), 'web'), $link, $post)
            ->assertStatus(403)->assertJsonPath('reason', 'approver_mismatch');
    });

    it('lets the invited address decide (any case) and writes the audit row', function () {
        [, $post, $link] = saLink();
        $approver = User::factory()->create(['email' => 'Rani@Client.TEST']);

        saDecide($this->actingAs(User::findOrFail($approver->id), 'web'), $link, $post, 'approve', ['name' => 'Rani from Acme'])
            ->assertOk()
            ->assertJsonPath('data.approval', 'approved')
            ->assertJsonPath('data.approval_by', 'Rani from Acme');

        $row = SocialApprovalDecision::query()->sole();
        expect($row->decision)->toBe('approved')
            ->and($row->social_approval_link_id)->toBe($link->id)
            ->and($row->social_post_id)->toBe($post->id)
            ->and($row->approver_user_id)->toBe($approver->id)
            ->and($row->approver_email)->toBe('rani@client.test')
            ->and($row->client_name)->toBe('Rani from Acme')
            ->and($row->ip)->toBe('127.0.0.1')
            ->and($row->user_agent)->toBe('SignedInApproverTest/1.0');
    });

    it('audits a request for changes and a take-back, and defaults the display name to the account', function () {
        [, $post, $link] = saLink();
        asApprover($this, 'rani@client.test');
        User::query()->where('email', 'rani@client.test')->update(['name' => 'Rani']);
        asApprover($this, 'rani@client.test');

        saDecide($this, $link, $post, 'revise', ['reason' => 'Warmer colours'])->assertOk()->assertJsonPath('data.approval_by', 'Rani');
        $this->postJson("/api/v1/a/{$link->token}/posts/{$post->ulid}/retract")->assertOk()->assertJsonPath('data.approval', 'client');

        expect(SocialApprovalDecision::query()->orderBy('id')->pluck('decision')->all())->toBe(['changes_requested', 'retracted']);
    });

    it('keeps decisions idempotent: the same approve twice is one decision and one audit row', function () {
        [, $post, $link] = saLink();
        asApprover($this, 'rani@client.test');

        saDecide($this, $link, $post)->assertOk();
        saDecide($this, $link, $post)->assertOk()->assertJsonPath('data.approval', 'approved');

        expect(SocialApprovalDecision::query()->count())->toBe(1);
    });

    it('approves all as the invited approver and audits each post', function () {
        [$owner, $post, $link] = saLink();
        $second = SocialPost::factory()->for($owner)->create(['provider_post_id' => null, 'status' => 'scheduled', 'approval' => 'client']);
        $second->targets()->create(['social_account_id' => SocialAccount::factory()->for($owner)->create()->id, 'status' => 'pending']);
        $link->posts()->attach($second->id);

        asApprover($this, 'rani@client.test')
            ->postJson("/api/v1/a/{$link->token}/approve-all", ['post_ids' => [$post->ulid, $second->ulid]])
            ->assertOk()->assertJsonPath('data.results.0.approved', true)->assertJsonPath('data.results.1.approved', true);

        expect(SocialApprovalDecision::query()->where('decision', 'approved')->count())->toBe(2);
    });

    it('shows the signed-in approver they can decide', function () {
        [, , $link] = saLink();

        asApprover($this, 'rani@client.test')->getJson("/api/v1/a/{$link->token}")
            ->assertOk()
            ->assertJsonPath('data.approver.signed_in', true)
            ->assertJsonPath('data.approver.signed_in_email', 'rani@client.test')
            ->assertJsonPath('data.approver.can_decide', true);
    });
});

describe('links made before signed-in approvers: bind on first use', function () {
    it('lets the first signed-in person decide, binds the link to them and tells the owner', function () {
        Notification::fake();
        [$owner, $post, $link] = saLink(null);

        $this->getJson("/api/v1/a/{$link->token}")->assertJsonPath('data.approver.bind_on_first_decision', true);

        saDecide(asApprover($this, 'First@Client.test'), $link, $post, 'revise', ['reason' => 'Shorter'])->assertOk();

        $link->refresh();
        expect($link->approver_email)->toBe('first@client.test')
            ->and($link->approver_bound_at)->not->toBeNull();
        Notification::assertSentTo($owner, ApprovalLinkBound::class, function (ApprovalLinkBound $n) use ($owner) {
            return $n->email === 'first@client.test'
                && $n->toMail($owner)->subject === 'Changes requested by first@client.test';
        });

        // From now on only that address decides.
        saDecide(asApprover($this, 'second@client.test'), $link, $post)->assertStatus(403)->assertJsonPath('reason', 'approver_mismatch');
        saDecide(asApprover($this, 'first@client.test'), $link, $post)->assertOk()->assertJsonPath('data.approval', 'approved');
        Notification::assertSentToTimes($owner, ApprovalLinkBound::class, 1);
    });

    it('says "Approved by {email}" when the binding decision is an approval', function () {
        Notification::fake();
        [$owner, $post, $link] = saLink(null);

        saDecide(asApprover($this, 'boss@client.test'), $link, $post)->assertOk();

        Notification::assertSentTo($owner, ApprovalLinkBound::class,
            fn (ApprovalLinkBound $n) => $n->toMail($owner)->subject === 'Approved by boss@client.test');
    });

    it('still needs a sign-in on an old link', function () {
        [, $post, $link] = saLink(null);

        saDecide($this, $link, $post)->assertStatus(401);
        expect($link->fresh()->approver_email)->toBeNull();
    });
});

describe('sign in by email link', function () {
    it('sends a one-time link only to the invited address, and always answers 202 the same way', function () {
        Notification::fake();
        [, , $link] = saLink();

        $match = $this->postJson("/api/v1/a/{$link->token}/email-link", ['email' => 'RANI@client.test'])->assertStatus(202);
        $other = $this->postJson("/api/v1/a/{$link->token}/email-link", ['email' => 'who@else.test'])->assertStatus(202);

        expect($other->json())->toBe($match->json());
        Notification::assertSentOnDemand(ApproverSignInLink::class,
            fn ($n, $channels, AnonymousNotifiable $to) => $to->routes['mail'] === 'rani@client.test');
        Notification::assertSentOnDemandTimes(ApproverSignInLink::class, 1);
        expect(DB::table('approver_login_tokens')->count())->toBe(1);
    });

    it('signs in (creating the account) once, then the link is dead', function () {
        Notification::fake();
        [, $post, $link] = saLink();

        $this->postJson("/api/v1/a/{$link->token}/email-link", ['email' => 'rani@client.test'])->assertStatus(202);
        $url = null;
        Notification::assertSentOnDemand(ApproverSignInLink::class, function (ApproverSignInLink $n) use (&$url) {
            $url = $n->url;

            return true;
        });
        $path = parse_url((string) $url, PHP_URL_PATH);

        $this->get($path)->assertRedirect($link->url());
        $user = User::query()->where('email', 'rani@client.test')->sole();
        expect($user->email_verified_at)->not->toBeNull();
        $this->assertAuthenticatedAs($user, 'web');

        // The same session can now decide.
        saDecide($this, $link, $post, 'revise', ['reason' => 'Ok, one change'])->assertOk();

        // Single use.
        auth('web')->logout();
        $this->get($path)->assertRedirect(rtrim((string) config('app.public_url'), '/').'/?signin=expired');
        $this->assertGuest('web');
    });

    it('expires after 15 minutes', function () {
        Notification::fake();
        [, , $link] = saLink();

        $this->postJson("/api/v1/a/{$link->token}/email-link", ['email' => 'rani@client.test']);
        $url = null;
        Notification::assertSentOnDemand(ApproverSignInLink::class, function (ApproverSignInLink $n) use (&$url) {
            $url = $n->url;

            return true;
        });

        $this->travel(16)->minutes();
        $this->get(parse_url((string) $url, PHP_URL_PATH))->assertRedirect(rtrim((string) config('app.public_url'), '/').'/?signin=expired');
        $this->assertGuest('web');
        expect(User::query()->where('email', 'rani@client.test')->exists())->toBeFalse();
    });

    it('throttles per address without telling: the 4th request in 15 minutes sends nothing', function () {
        Notification::fake();
        [, , $link] = saLink();

        foreach (range(1, 4) as $i) {
            $this->postJson("/api/v1/a/{$link->token}/email-link", ['email' => 'rani@client.test'])->assertStatus(202);
        }

        Notification::assertSentOnDemandTimes(ApproverSignInLink::class, 3);
    });

    it('rate-limits the endpoint per IP', function () {
        Notification::fake();
        [, , $link] = saLink();

        foreach (range(1, 5) as $i) {
            $this->postJson("/api/v1/a/{$link->token}/email-link", ['email' => "x{$i}@else.test"])->assertStatus(202);
        }
        $this->postJson("/api/v1/a/{$link->token}/email-link", ['email' => 'x6@else.test'])->assertStatus(429);
    });

    it('validates the address', function () {
        [, , $link] = saLink();

        $this->postJson("/api/v1/a/{$link->token}/email-link", ['email' => 'not-an-address'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    });
});

describe('creating and updating links', function () {
    it('requires approver_email on a new link with a clear field error', function () {
        [$owner, $post] = saLink();
        $draft = SocialPost::factory()->for($owner)->create(['status' => 'draft', 'provider_post_id' => null, 'scheduled_at' => null]);

        $this->actingAs($owner)->postJson('/api/v1/social-approval-links', ['post_ids' => [$draft->ulid]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['approver_email' => 'Add the email address of the person who approves.']);

        $this->actingAs($owner)->postJson('/api/v1/social-approval-links', ['post_ids' => [$draft->ulid], 'approver_email' => 'nope'])
            ->assertUnprocessable()->assertJsonValidationErrors('approver_email');

        $this->actingAs($owner)->postJson('/api/v1/social-approval-links', ['post_ids' => [$draft->ulid], 'approver_email' => ' Rani@Client.test '])
            ->assertCreated()
            ->assertJsonPath('data.approver_email', 'rani@client.test');
    });

    it('changes the approver on update, and the new address is the one that decides', function () {
        [$owner, $post, $link] = saLink();

        $this->actingAs($owner)->patchJson("/api/v1/social-approval-links/{$link->ulid}", ['approver_email' => 'new@client.test'])
            ->assertOk()->assertJsonPath('data.link.approver_email', 'new@client.test');

        saDecide(asApprover($this, 'rani@client.test'), $link, $post)->assertStatus(403);
        saDecide(asApprover($this, 'new@client.test'), $link, $post)->assertOk();
    });

    it('takes approver_email through an API key (create_approval_link) and refuses one without it', function () {
        ensurePassportKeys();
        [$owner] = saLink();
        $draft = SocialPost::factory()->for($owner)->create(['status' => 'draft', 'provider_post_id' => null, 'scheduled_at' => null]);
        $key = $this->actingAs($owner, 'web')->postJson('/api/v1/me/api-keys', ['name' => 'k', 'scopes' => ['read', 'approve']])->json('data.key');
        app('auth')->forgetGuards();

        bearer($this, $key)->postJson('/api/v1/social-approval-links', ['post_ids' => [$draft->ulid]])
            ->assertUnprocessable()->assertJsonValidationErrors('approver_email');

        bearer($this, $key)->postJson('/api/v1/social-approval-links', ['post_ids' => [$draft->ulid], 'approver_email' => 'rani@client.test'])
            ->assertCreated()->assertJsonPath('data.approver_email', 'rani@client.test');
    });
});

describe('Free: one client, counted by approver_email', function () {
    it('treats links to the same address as one client, whatever they are named', function () {
        $user = saFree();

        $this->actingAs($user)->postJson('/api/v1/social-approval-links', ['post_ids' => [saDraft($user)->ulid], 'approver_email' => 'rani@client.test', 'client_name' => 'Acme'])->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/social-approval-links', ['post_ids' => [saDraft($user)->ulid], 'approver_email' => 'RANI@client.test', 'client_name' => 'Globex'])->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/social-approval-links', ['post_ids' => [saDraft($user)->ulid], 'approver_email' => 'other@client.test', 'client_name' => 'Acme'])
            ->assertStatus(402)->assertJsonPath('feature', 'approval_clients');
    });

    it('falls back to client_name for old links without an approver', function () {
        $user = saFree();
        SocialApprovalLink::create(['user_id' => $user->id, 'client_name' => 'Acme']);

        // The old link's client is "Acme" (by name); a link to an address is a second client.
        $this->actingAs($user)->postJson('/api/v1/social-approval-links', ['post_ids' => [saDraft($user)->ulid], 'approver_email' => 'rani@client.test', 'client_name' => 'Acme'])
            ->assertStatus(402)->assertJsonPath('feature', 'approval_clients');
    });
});

describe('waiting your approval', function () {
    it('lists live links sent to my verified email with posts waiting on me', function () {
        [$owner, $post, $link] = saLink();
        [, , $otherLink] = saLink('someone@else.test');
        $revoked = SocialApprovalLink::create(['user_id' => $owner->id, 'approver_email' => 'rani@client.test', 'revoked_at' => now()]);
        $revoked->posts()->attach($post->id);

        asApprover($this, 'rani@client.test')->getJson('/api/v1/me/approvals/waiting')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.link.url', $link->url())
            ->assertJsonPath('data.0.link.editor', $owner->name)
            ->assertJsonPath('data.0.waiting', 1)
            ->assertJsonPath('data.0.posts.0.id', $post->ulid);

        // Decided: nothing waiting any more.
        saDecide($this, $link, $post, 'revise', ['reason' => 'x'])->assertOk();
        $this->getJson('/api/v1/me/approvals/waiting')->assertOk()->assertJsonCount(0, 'data');
        expect($otherLink->approver_email)->toBe('someone@else.test');
    });

    it('needs a sign-in', function () {
        $this->getJson('/api/v1/me/approvals/waiting')->assertUnauthorized();
    });
});

it('masks addresses for the hint', function () {
    expect(ApproverGate::hint('dewi@kopi.example'))->toBe('d***@k***.example')
        ->and(ApproverGate::hint('a@b.example.org'))->toBe('a***@b***.org');
});
