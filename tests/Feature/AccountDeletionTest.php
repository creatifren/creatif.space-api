<?php

use App\Enums\UserStatus;
use App\Models\File;
use App\Models\Invoice;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPlanLink;
use App\Models\SocialPost;
use App\Models\Space;
use App\Models\Subscription;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Notifications\AccountDeleted;
use App\Notifications\AccountDeletionCancelled;
use App\Notifications\AccountDeletionScheduled;
use App\Services\Analytics;
use App\Support\AccountDeletion;
use App\Support\Credits;
use App\Support\Workspace;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
 * Account deletion (Play Store requirement): request → 30-day grace (423 on
 * everything but cancel) → purge that keeps billing and the approval audit.
 */

beforeEach(function () {
    Workspace::forget();
    Carbon::setTestNow(Carbon::parse('2026-11-12 09:00:00', 'UTC'));
    Storage::fake('s3');
    Notification::fake();
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
        'app.frontend_url' => 'https://app.test',
        'services.google_play.package_name' => 'space.creatif.app',
    ]);
    Http::fake([
        'api.postforme.test/*' => Http::response([]),
        'test-api.creem.io/*' => Http::response(['id' => 'sub_test']),
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function adUser(array $attrs = []): User
{
    return User::factory()->create($attrs + ['email' => 'rina@studio.test', 'name' => 'Rina Studio']);
}

function adDelete($test, User $user, array $body = ['confirm' => 'DELETE'])
{
    return $test->actingAs($user)->deleteJson('/api/v1/me', $body);
}

describe('requesting deletion', function () {
    it('refuses without confirm: "DELETE"', function (array $body) {
        $user = adUser();

        adDelete($this, $user, $body)->assertUnprocessable()->assertJsonValidationErrors('confirm');
        expect($user->fresh()->status)->toBe(UserStatus::Active);
    })->with([
        'missing' => [[]],
        'lower case' => [['confirm' => 'delete']],
        'other' => [['confirm' => 'yes']],
    ]);

    it('marks the account pending, revokes every way in, and mails the purge date', function () {
        $user = adUser();
        $user->createToken('mcp key');
        DB::table('sessions')->insert(['id' => 'sess1', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => now()->getTimestamp()]);

        adDelete($this, $user)
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending_deletion')
            ->assertJsonPath('data.purge_at', '2026-12-12T09:00:00+00:00')
            ->assertJsonPath('data.play_manage_url', null);

        $user->refresh();
        expect($user->status)->toBe(UserStatus::PendingDeletion)
            ->and($user->deletion_requested_at->utc()->toIso8601String())->toBe('2026-11-12T09:00:00+00:00')
            ->and($user->tokens()->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
        Notification::assertSentTo($user, AccountDeletionScheduled::class, fn ($n) => $n->toMail($user)->subject === 'Deletion scheduled for 12 December 2026');
    });

    it('cancels an active Creem subscription through the existing service', function () {
        $user = adUser();
        Subscription::factory()->for($user)->create(['creem_subscription_id' => 'sub_test']);

        adDelete($this, $user)->assertStatus(202)->assertJsonPath('data.play_manage_url', null);

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/subscriptions/sub_test/cancel') && $r['mode'] === 'scheduled');
        expect($user->subscriptions()->first()->cancelled_at)->not->toBeNull();
    });

    it('does not cancel a Play subscription server-side; returns where to manage it', function () {
        $user = adUser();
        Subscription::factory()->for($user)->create(['provider' => 'google', 'google_product_id' => 'pro_monthly', 'creem_subscription_id' => null]);

        adDelete($this, $user)
            ->assertStatus(202)
            ->assertJsonPath('data.play_manage_url', 'https://play.google.com/store/account/subscriptions?sku=pro_monthly&package=space.creatif.app');

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'creem'));
        expect($user->subscriptions()->first()->cancelled_at)->toBeNull();
        Notification::assertSentTo($user, AccountDeletionScheduled::class, fn ($n) => $n->playManageUrl !== null);
    });

    it('cancels scheduled posts with their Credits back, closes links (public 404), disconnects channels, drops team seats', function () {
        $user = adUser();
        Credits::grant($user, 10_000, 'Test');
        $account = SocialAccount::factory()->for($user)->create(['platform' => 'linkedin']);
        $sent = SocialPost::factory()->for($user)->create(['status' => 'scheduled', 'provider_post_id' => 'p1']);
        $sent->targets()->create(['social_account_id' => $account->id, 'status' => 'pending', 'charged_credits' => 300]);
        $held = SocialPost::factory()->for($user)->create(['status' => 'scheduled', 'provider_post_id' => null, 'approval' => 'client']);
        $link = SocialApprovalLink::create(['user_id' => $user->id, 'client_name' => 'Acme']);
        $plan = SocialPlanLink::create(['user_id' => $user->id, 'title' => 'Nov', 'starts_at' => now(), 'ends_at' => now()->addMonth()]);
        $other = User::factory()->create();
        TeamMember::factory()->for($other, 'owner')->active($user)->create();

        adDelete($this, $user)->assertStatus(202);

        expect($sent->fresh()->status->value)->toBe('cancelled')
            ->and($held->fresh()->status->value)->toBe('cancelled')
            ->and($account->fresh()->status->value)->toBe('disconnected')
            ->and($link->fresh()->revoked_at)->not->toBeNull()
            ->and($plan->fresh()->revoked_at)->not->toBeNull()
            ->and(TeamMember::query()->count())->toBe(0);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'p1'));
        $this->getJson("/api/v1/a/{$link->token}")->assertNotFound();
        $this->getJson("/api/v1/plan/{$plan->token}")->assertNotFound();
    });
});

describe('the grace period', function () {
    it('lets a pending account sign in, answers 423 everywhere else, and cancel brings it back', function () {
        $user = adUser(['password' => 'secret-pass-123']);
        AccountDeletion::request($user);

        $this->postJson('/auth/login', ['email' => 'rina@studio.test', 'password' => 'secret-pass-123'])->assertSuccessful();

        // /me answers, with the pending state, so the app can offer to keep the account.
        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_deletion')
            ->assertJsonPath('data.deletion_requested_at', '2026-11-12T09:00:00+00:00')
            ->assertJsonPath('data.purge_at', '2026-12-12T09:00:00+00:00')
            ->assertJsonPath('data.capabilities.account_deletion', true);

        foreach (['/api/v1/social-posts', '/api/v1/credits', '/api/v1/me/api-keys', '/api/v1/me/profile'] as $url) {
            $this->getJson($url)
                ->assertStatus(423)
                ->assertJsonPath('reason', 'account_pending_deletion')
                ->assertJsonPath('purge_at', '2026-12-12T09:00:00+00:00');
        }

        $this->postJson('/api/v1/me/deletion/cancel')->assertOk()->assertJsonPath('data.status', 'active');
        expect($user->fresh()->status)->toBe(UserStatus::Active)->and($user->fresh()->deletion_requested_at)->toBeNull();
        Notification::assertSentTo($user, AccountDeletionCancelled::class);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.purge_at', null)->assertJsonPath('data.deletion_requested_at', null);
        $this->getJson('/api/v1/credits')->assertOk();
        $this->postJson('/api/v1/me/deletion/cancel')->assertStatus(409);
    });
});

describe('the purge', function () {
    /** A full account: content, files on R2, billing, and approval audit rows. */
    function adFull(): array
    {
        $user = adUser(['google_id' => 'g-123', 'avatar_url' => 'https://cdn/avatar.jpg']);
        Subscription::factory()->for($user)->create();
        Credits::grant($user, 5_000, 'Test');
        $account = SocialAccount::factory()->for($user)->create();
        $post = SocialPost::factory()->for($user)->create(['status' => 'published', 'caption' => 'Private caption']);
        $post->targets()->create(['social_account_id' => $account->id, 'status' => 'published']);
        $link = SocialApprovalLink::create(['user_id' => $user->id, 'client_name' => 'Acme']);
        $link->posts()->attach($post->id);
        DB::table('social_approval_decisions')->insert([
            'social_approval_link_id' => $link->id, 'social_post_id' => $post->id, 'decision' => 'approved',
            'approver_email' => 'client@acme.test', 'client_name' => 'Client', 'created_at' => now(),
        ]);
        // Rina approved someone else's post: her own address in their audit trail.
        $theirs = SocialApprovalLink::create(['user_id' => User::factory()->create()->id, 'approver_email' => 'rina@studio.test']);
        DB::table('social_approval_decisions')->insert([
            'social_approval_link_id' => $theirs->id, 'social_post_id' => null, 'decision' => 'approved',
            'approver_email' => 'rina@studio.test', 'created_at' => now(),
        ]);
        $file = File::factory()->for($user)->create(['path' => 'u/rina/photo.jpg']);
        Storage::disk('s3')->put('u/rina/photo.jpg', 'bytes');
        $space = Space::factory()->for($user)->create();
        $space->items()->create(['file_id' => $file->id, 'sort_order' => 0]);
        $user->handle()->forceCreate(['name' => 'rina', 'user_id' => $user->id]);
        $user->profile()->create(['bio' => 'Hello']);
        AccountDeletion::request($user->fresh());

        return [$user->fresh(), $file, $post, $link];
    }

    it('respects the 30-day grace window', function () {
        [$user] = adFull();

        Carbon::setTestNow(now()->addDays(30)->subMinute());
        $this->artisan('accounts:purge-deleted')->assertSuccessful();
        expect(User::query()->find($user->id))->not->toBeNull()
            ->and(SocialPost::query()->where('user_id', $user->id)->count())->toBe(1);
    });

    it('removes content and R2 objects, keeps billing and the approval audit (anonymised), mails first, deletes the PostHog person', function () {
        $fake = Analytics::fake();
        [$user, $file, $post] = adFull();
        $subscriptions = Subscription::query()->where('user_id', $user->id)->count();
        $wallet = WalletTransaction::query()->where('user_id', $user->id)->count();
        $invoices = Invoice::query()->whereIn('subscription_id', Subscription::query()->where('user_id', $user->id)->select('id'))->count();

        Carbon::setTestNow(now()->addDays(30)->addMinute());
        $this->artisan('accounts:purge-deleted')->assertSuccessful();

        // Content gone, rows and objects.
        expect(SocialPost::query()->where('user_id', $user->id)->count())->toBe(0)
            ->and(SocialAccount::query()->where('user_id', $user->id)->count())->toBe(0)
            ->and(SocialApprovalLink::query()->where('user_id', $user->id)->count())->toBe(0)
            ->and(Space::withTrashed()->where('user_id', $user->id)->count())->toBe(0)
            ->and(File::withTrashed()->where('user_id', $user->id)->count())->toBe(0)
            ->and(DB::table('handles')->where('user_id', $user->id)->count())->toBe(0)
            ->and(DB::table('profiles')->where('user_id', $user->id)->count())->toBe(0);
        Storage::disk('s3')->assertMissing('u/rina/photo.jpg');

        // Billing kept.
        expect(Subscription::query()->where('user_id', $user->id)->count())->toBe($subscriptions)
            ->and(WalletTransaction::query()->where('user_id', $user->id)->count())->toBe($wallet)
            ->and(Invoice::query()->whereIn('subscription_id', Subscription::query()->where('user_id', $user->id)->select('id'))->count())->toBe($invoices);

        // Approval audit kept: their own link's decision survives its post; her address is gone.
        $placeholder = 'deleted+'.strtolower($user->ulid).'@invalid';
        expect(DB::table('social_approval_decisions')->count())->toBe(2)
            ->and(DB::table('social_approval_decisions')->where('approver_email', 'client@acme.test')->value('social_post_id'))->toBeNull()
            ->and(DB::table('social_approval_decisions')->where('approver_email', 'rina@studio.test')->count())->toBe(0)
            ->and(DB::table('social_approval_decisions')->where('approver_email', $placeholder)->count())->toBe(1)
            ->and(DB::table('social_approval_links')->where('approver_email', 'rina@studio.test')->count())->toBe(0);

        // The user row: anonymised, then soft-deleted.
        $row = User::withTrashed()->find($user->id);
        expect($row->trashed())->toBeTrue()
            ->and($row->name)->toBe('Deleted user')
            ->and($row->email)->toBe($placeholder)
            ->and($row->google_id)->toBeNull()
            ->and($row->avatar_url)->toBeNull()
            ->and($row->status)->toBe(UserStatus::Deleted);

        Notification::assertSentOnDemand(AccountDeleted::class, fn ($n, $channels, AnonymousNotifiable $to) => $to->routes['mail'] === 'rina@studio.test');
        expect($fake->deletedPersons)->toBe([$user->ulid]);
    });

    it('removes Insight share links and reports, feedback, exports and the AI style; anonymises the approver audit she left on others\' links', function () {
        [$user] = adFull();
        $other = User::factory()->create();
        $ulid = fn () => (string) str()->ulid();
        $share = fn (int $owner, ?string $recipient = null) => DB::table('insight_share_links')->insertGetId([
            'ulid' => $ulid(), 'owner_id' => $owner, 'user_id' => $owner, 'token_hash' => hash('sha256', $ulid()),
            'period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'period_kind' => 'month', 'channel_ids' => '[]',
            'recipient_email' => $recipient, 'recipient_token' => $recipient ? 'enc' : null,
            'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $mine = $share($user->id);
        $sharedWithHer = $share($other->id, 'rina@studio.test');
        DB::table('insight_reports')->insert([
            'ulid' => $ulid(), 'user_id' => $user->id, 'owner_id' => $user->id, 'client_id' => 'c1', 'format' => 'onepage', 'locale' => 'en',
            'period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'options' => '{}', 'status' => 'done',
            'path' => 'insight/rina/report.pdf', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Storage::disk('s3')->put('insight/rina/report.pdf', 'pdf');
        DB::table('feedback')->insert(['user_id' => $user->id, 'kind' => 'idea', 'message' => 'Private idea', 'source' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('account_exports')->insert(['ulid' => $ulid(), 'user_id' => $user->id, 'status' => 'done', 'path' => 'exports/rina.zip', 'created_at' => now(), 'updated_at' => now()]);
        Storage::disk('s3')->put('exports/rina.zip', 'zip');
        DB::table('ai_style_profiles')->insert(['user_id' => $user->id, 'tone' => 'warm', 'created_at' => now(), 'updated_at' => now()]);
        // Signed in as an approver on someone else's link: account, IP and browser on the row.
        $theirLink = SocialApprovalLink::create(['user_id' => $other->id, 'approver_email' => 'someone@else.test']);
        DB::table('social_approval_decisions')->insert([
            'social_approval_link_id' => $theirLink->id, 'social_post_id' => null, 'decision' => 'changes_requested',
            'approver_user_id' => $user->id, 'approver_email' => 'rina+work@studio.test', 'ip' => '203.0.113.9', 'user_agent' => 'Firefox',
            'comment' => 'Swap the photo', 'created_at' => now(),
        ]);

        Carbon::setTestNow(now()->addDays(31));
        $this->artisan('accounts:purge-deleted')->assertSuccessful();

        expect(DB::table('insight_share_links')->where('id', $mine)->exists())->toBeFalse()
            ->and(DB::table('insight_reports')->where('owner_id', $user->id)->count())->toBe(0)
            ->and(DB::table('feedback')->where('user_id', $user->id)->count())->toBe(0)
            ->and(DB::table('account_exports')->where('user_id', $user->id)->count())->toBe(0)
            ->and(DB::table('ai_style_profiles')->where('user_id', $user->id)->count())->toBe(0);
        Storage::disk('s3')->assertMissing('insight/rina/report.pdf');
        Storage::disk('s3')->assertMissing('exports/rina.zip');

        // The other owner's link stays theirs, without her address.
        $theirs = DB::table('insight_share_links')->where('id', $sharedWithHer)->first();
        expect($theirs)->not->toBeNull()
            ->and($theirs->recipient_email)->toBeNull()
            ->and($theirs->recipient_token)->toBeNull();

        // The audit row stays for the other owner — decision and comment — but not who she was.
        $row = DB::table('social_approval_decisions')->where('social_approval_link_id', $theirLink->id)->first();
        expect($row->decision)->toBe('changes_requested')
            ->and($row->comment)->toBe('Swap the photo')
            ->and($row->approver_user_id)->toBeNull()
            ->and($row->approver_email)->toBe('deleted+'.strtolower($user->ulid).'@invalid')
            ->and($row->ip)->toBeNull()
            ->and($row->user_agent)->toBeNull();
    });

    it('does nothing on a re-run', function () {
        [$user] = adFull();
        Carbon::setTestNow(now()->addDays(31));
        $this->artisan('accounts:purge-deleted')->assertSuccessful();
        Notification::fake();

        $this->artisan('accounts:purge-deleted')->expectsOutputToContain('Purged 0 account(s)')->assertSuccessful();

        Notification::assertNothingSent();
        expect(User::withTrashed()->find($user->id)->email)->toStartWith('deleted+');
    });

    it('leaves a cancelled request alone', function () {
        [$user] = adFull();
        AccountDeletion::cancel($user);

        Carbon::setTestNow(now()->addDays(40));
        $this->artisan('accounts:purge-deleted')->assertSuccessful();

        expect(User::query()->find($user->id)?->email)->toBe('rina@studio.test');
    });

    it('is scheduled daily', function () {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'accounts:purge-deleted'));
        expect($event?->expression)->toBe('25 3 * * *');
    });
});

it('advertises the capability on /me for every account', function () {
    $this->actingAs(adUser()->fresh())->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.capabilities.account_deletion', true)
        ->assertJsonPath('data.deletion_requested_at', null)
        ->assertJsonPath('data.purge_at', null);
});
