<?php

use App\Jobs\BuildAccountExport;
use App\Jobs\PruneAccountExports;
use App\Models\AccountExport;
use App\Models\File;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialApprovalDecision;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\Space;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\AccountExportReady;
use App\Support\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/*
 * Account data export — POST /me/export → BuildAccountExport → a ZIP of
 * JSON, emailed as a 24-hour signed link. No tokens or secrets; never
 * another workspace's rows. Session only (no MCP tool).
 */

beforeEach(function () {
    Storage::fake('s3');
    Notification::fake();
    Workspace::forget();
    ensurePassportKeys();
});

/** An owner with a channel, a post, an approval link with a decision, and a file. */
function axWorkspace(string $email): User
{
    $owner = User::query()->findOrFail(User::factory()->create(['email' => $email, 'name' => 'Owner '.$email, 'password' => bcrypt('secret-password')])->id);
    $account = SocialAccount::factory()->for($owner)->create(['username' => 'handle_'.$email]);
    $post = SocialPost::factory()->for($owner)->create([
        'caption' => 'Caption of '.$email, 'provider_post_id' => 'prov_SECRET_'.$email,
        'raw_payload' => ['access_token' => 'RAWTOKEN_'.$email], 'status' => 'scheduled', 'scheduled_at' => now()->addDay(),
    ]);
    $post->targets()->create(['social_account_id' => $account->id, 'status' => 'pending', 'provider_error' => 'PROVIDER_ERR_'.$email]);
    $link = SocialApprovalLink::create(['user_id' => $owner->id, 'title' => 'Link of '.$email, 'approver_email' => 'client@'.$email]);
    $link->posts()->attach($post->id);
    SocialApprovalDecision::create([
        'social_approval_link_id' => $link->id, 'social_post_id' => $post->id, 'decision' => 'changes_requested',
        'approver_email' => 'client@'.$email, 'client_name' => 'Client', 'comment' => 'Swap photo', 'source' => 'web',
        'ip' => '203.0.113.77', 'user_agent' => 'SecretAgent/1.0',
    ]);
    File::factory()->for($owner)->create(['name' => 'photo-of-'.$email.'.jpg']);

    return $owner;
}

/** @return array<string, string> file name => contents */
function axUnzip(AccountExport $export): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'ax');
    file_put_contents($tmp, Storage::disk('s3')->get($export->path));
    $zip = new ZipArchive;
    $zip->open($tmp);
    $out = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $out[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
    }
    $zip->close();
    unlink($tmp);

    return $out;
}

function axRun(TestCase $test, User $user): AccountExport
{
    Queue::fake();
    $test->actingAs($user)->postJson('/api/v1/me/export')->assertStatus(202)->assertJsonPath('data.status', 'queued');
    Queue::assertPushed(BuildAccountExport::class, 1);
    $export = AccountExport::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
    (new BuildAccountExport($export->id))->handle();

    return $export->fresh();
}

it('builds the ZIP with every section and emails a 24-hour signed link', function () {
    $owner = axWorkspace('a.test');
    $export = axRun($this, $owner);

    expect($export->status)->toBe('done')
        ->and($export->expires_at->diffInHours(now(), true))->toEqualWithDelta(24, 0.1);
    $files = axUnzip($export);
    expect(array_keys($files))->toEqualCanonicalizing([
        'README.txt', 'account.json', 'workspace.json', 'channels.json', 'posts.json', 'approvals.json',
        'approval_audit.json', 'insight_share_links.json', 'notification_settings.json', 'spaces.json', 'files.json',
    ]);

    $posts = json_decode($files['posts.json'], true);
    expect($posts[0])->toMatchArray(['caption' => 'Caption of a.test', 'status' => 'scheduled']);
    $approvals = json_decode($files['approvals.json'], true);
    expect($approvals[0]['audit'][0])->toMatchArray(['decision' => 'changes_requested', 'comment' => 'Swap photo', 'approver_email' => 'client@a.test']);
    $library = json_decode($files['files.json'], true);
    expect($library['files'][0]['name'])->toBe('photo-of-a.test.jpg')
        ->and($library['files'][0]['url'])->toStartWith('http')
        ->and(now()->diffInDays($library['urls_expire_at'], true))->toEqualWithDelta(7, 0.01);

    Notification::assertSentTo($owner, AccountExportReady::class, function (AccountExportReady $n) use ($owner, $export) {
        $mail = $n->toMail($owner);

        return $mail->actionUrl === $export->signedUrl() && str_contains($mail->actionUrl, 'signature=');
    });
});

it('includes Insight share links, notification settings and the approval audit — never a link token', function () {
    $owner = axWorkspace('a.test');
    $tokenHash = str_repeat('f', 64);
    DB::table('insight_share_links')->insert([
        'ulid' => (string) str()->ulid(), 'owner_id' => $owner->id, 'user_id' => $owner->id, 'token_hash' => $tokenHash,
        'period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'period_kind' => 'month', 'channel_ids' => json_encode(['01JCHANNEL0000000000000001']),
        'recipient_email' => 'boss@client.test', 'recipient_token' => Crypt::encryptString('SHARE_TOKEN_PLAIN'),
        'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($owner)->patchJson('/api/v1/me/notification-settings', ['weekly_digest' => false, 'timezone' => 'Indian/Maldives'])->assertOk();
    // The owner, as an approver on someone else's link.
    $theirs = SocialApprovalLink::create(['user_id' => User::factory()->create()->id, 'title' => 'Their plan', 'approver_email' => 'a.test@owner.test']);
    SocialApprovalDecision::create([
        'social_approval_link_id' => $theirs->id, 'social_post_id' => null, 'decision' => 'approved', 'approver_user_id' => $owner->id,
        'approver_email' => 'a.test@owner.test', 'comment' => 'Looks good', 'source' => 'mcp', 'ip' => '198.51.100.4', 'user_agent' => 'OtherAgent',
    ]);

    $files = axUnzip(axRun($this, $owner));

    $shares = json_decode($files['insight_share_links.json'], true);
    expect($shares)->toHaveCount(1)
        ->and($shares[0])->toMatchArray([
            'period' => ['from' => '2026-10-01', 'to' => '2026-10-31', 'kind' => 'month'],
            'channels' => ['01JCHANNEL0000000000000001'],
            'recipient_email' => 'boss@client.test',
            'status' => 'live',
        ]);

    $settings = json_decode($files['notification_settings.json'], true);
    expect($settings['settings'])->toBe(['publish_failed' => true, 'approval_decided' => true, 'weekly_digest' => false, 'timezone' => 'Indian/Maldives'])
        ->and(collect($settings['preferences'])->firstWhere('type', 'digest.weekly'))->toBe(['type' => 'digest.weekly', 'email' => false, 'bell' => true]);

    $audit = json_decode($files['approval_audit.json'], true);
    expect($audit['on_your_links'])->toHaveCount(1)
        ->and($audit['on_your_links'][0])->toMatchArray(['link_title' => 'Link of a.test', 'decision' => 'changes_requested', 'comment' => 'Swap photo', 'approver_email' => 'client@a.test'])
        ->and($audit['your_decisions'])->toBe([[
            'link_id' => $theirs->ulid, 'link_title' => 'Their plan', 'decided_at' => $audit['your_decisions'][0]['decided_at'],
            'decision' => 'approved', 'comment' => 'Looks good', 'source' => 'mcp',
        ]]);

    $all = implode("\n", $files);
    foreach ([$tokenHash, 'SHARE_TOKEN_PLAIN', 'recipient_token', '198.51.100.4', 'OtherAgent'] as $secret) {
        expect($all)->not->toContain($secret);
    }
});

it('includes each link\'s posts — a Content Plan\'s by its dates and channels — and Spaces metadata, never file bytes or a password', function () {
    $owner = axWorkspace('a.test');
    $ig = SocialAccount::query()->where('user_id', $owner->id)->firstOrFail();
    $other = SocialAccount::factory()->for($owner)->create(['username' => 'other_channel']);
    $plan = SocialApprovalLink::create([
        'user_id' => $owner->id, 'title' => 'December plan', 'approver_email' => 'client@a.test',
        'scope_from' => '2026-12-01', 'scope_to' => '2026-12-31', 'scope_timezone' => 'UTC',
        'scope_starts_at' => CarbonImmutable::parse('2026-12-01 00:00:00', 'UTC'),
        'scope_ends_at' => CarbonImmutable::parse('2027-01-01 00:00:00', 'UTC'),
        'scope_account_ids' => [$ig->id],
    ]);
    $inPlan = SocialPost::factory()->for($owner)->create(['status' => 'scheduled', 'approval' => 'client', 'scheduled_at' => CarbonImmutable::parse('2026-12-10 09:00:00', 'UTC')]);
    $inPlan->targets()->create(['social_account_id' => $ig->id, 'status' => 'pending']);
    $otherChannel = SocialPost::factory()->for($owner)->create(['status' => 'scheduled', 'approval' => 'client', 'scheduled_at' => CarbonImmutable::parse('2026-12-11 09:00:00', 'UTC')]);
    $otherChannel->targets()->create(['social_account_id' => $other->id, 'status' => 'pending']);

    $file = File::query()->where('user_id', $owner->id)->firstOrFail();
    $space = Space::factory()->for($owner)->create(['title' => 'Lookbook', 'password_hash' => bcrypt('space-secret')]);
    $space->items()->create(['file_id' => $file->id, 'sort_order' => 0, 'section' => 'Hero', 'caption' => 'Cover shot']);

    $files = axUnzip(axRun($this, $owner));

    $links = collect(json_decode($files['approvals.json'], true))->keyBy('title');
    expect(collect($links['December plan']['items'])->pluck('post_id')->all())->toBe([$inPlan->ulid])
        ->and($links['December plan']['items'][0])->toMatchArray(['status' => 'scheduled', 'approval' => 'client'])
        ->and(collect($links['Link of a.test']['items'])->pluck('post_id')->all())
        ->toBe([SocialPost::query()->where('caption', 'Caption of a.test')->value('ulid')]);

    $spaces = json_decode($files['spaces.json'], true);
    expect($spaces)->toHaveCount(1)
        ->and($spaces[0])->toMatchArray(['id' => $space->ulid, 'title' => 'Lookbook', 'password_protected' => true])
        ->and($spaces[0]['items'])->toBe([[
            'file_id' => $file->ulid, 'file_name' => 'photo-of-a.test.jpg', 'section' => 'Hero', 'caption' => 'Cover shot', 'position' => 0,
        ]]);
    expect($files['spaces.json'])->not->toContain('password_hash')->not->toContain('$2y$');
});

it('never includes tokens, secrets, provider ids, IPs or user agents', function () {
    $owner = axWorkspace('a.test');
    $owner->forceFill(['google_id' => 'GOOGLE_ID_SECRET', 'remember_token' => 'REMEMBER_SECRET'])->save();
    $this->actingAs($owner, 'web')->postJson('/api/v1/me/api-keys', ['name' => 'Claude', 'scopes' => ['read']])->assertCreated();
    app('auth')->forgetGuards();

    $all = implode("\n", axUnzip(axRun($this, $owner)));
    foreach (['secret-password', '$2y$', 'GOOGLE_ID_SECRET', 'REMEMBER_SECRET', 'RAWTOKEN_', 'prov_SECRET_', 'PROVIDER_ERR_', '203.0.113.77', 'SecretAgent', 'access_token', 'token_hash', 'cs_live_', 'cs_test_'] as $secret) {
        expect($all)->not->toContain($secret);
    }
});

it('never includes another workspace, and includes the team’s own posts', function () {
    $a = axWorkspace('a.test');
    axWorkspace('b.test');
    $a->subscriptions()->create(['plan_id' => Plan::query()->where('key', 'team')->value('id'), 'billing_period' => 'monthly', 'seats' => 3, 'status' => 'active']);
    $member = User::factory()->create();
    TeamMember::factory()->create(['owner_id' => $a->id, 'member_id' => $member->id, 'email' => $member->email, 'role' => 'editor', 'status' => 'active', 'joined_at' => now()]);
    SocialPost::factory()->for($member)->create(['caption' => 'Member post in A']);
    Workspace::forget();

    $all = implode("\n", axUnzip(axRun($this, $a)));
    expect($all)->toContain('Caption of a.test')->toContain('Member post in A')
        ->not->toContain('b.test');
});

it('allows one running export per user (409), and a new one once it is done', function () {
    Queue::fake();
    $user = axWorkspace('a.test');
    $first = $this->actingAs($user)->postJson('/api/v1/me/export')->assertStatus(202)->json('data.id');
    $this->actingAs($user)->postJson('/api/v1/me/export')
        ->assertStatus(409)->assertJsonPath('reason', 'export_running')->assertJsonPath('data.id', $first);
    Queue::assertPushed(BuildAccountExport::class, 1);

    // Someone else is not blocked.
    $this->actingAs(axWorkspace('b.test'))->postJson('/api/v1/me/export')->assertStatus(202);

    AccountExport::query()->where('ulid', $first)->update(['status' => 'done']);
    $this->actingAs($user)->postJson('/api/v1/me/export')->assertStatus(202);
    $this->actingAs($user)->getJson('/api/v1/me/export')->assertOk()->assertJsonPath('data.status', 'queued');
});

it('serves the ZIP from the signed link only, and not after 24 hours', function () {
    $export = axRun($this, axWorkspace('a.test'));
    app('auth')->forgetGuards();
    $this->flushSession();

    $this->get($export->signedUrl())->assertOk()->assertHeader('Content-Type', 'application/zip');
    $this->get("/api/v1/me/export/{$export->ulid}/download")->assertForbidden();

    $this->travel(25)->hours();
    $this->get($export->signedUrl())->assertStatus(403);
    $export->update(['expires_at' => now()->addHour()]);
    $this->get(URL::temporarySignedRoute('api.v1.me.export.download', now()->addMinute(), ['export' => $export->ulid]))->assertOk();
});

it('is session only: an API key or MCP token gets 403, signed out 401', function () {
    // Signed out first: bearer() keeps its Authorization header for the rest of the test.
    $this->postJson('/api/v1/me/export')->assertUnauthorized();

    $user = axWorkspace('a.test');
    $key = $this->actingAs($user, 'web')->postJson('/api/v1/me/api-keys', ['name' => 'Claude', 'scopes' => ['read', 'draft', 'approve', 'publish']])->json('data.key');
    app('auth')->forgetGuards();

    bearer($this, $key)->postJson('/api/v1/me/export')->assertForbidden();
    expect(AccountExport::query()->count())->toBe(0);
});

it('marks a failed build failed, and lets the user try again', function () {
    Queue::fake();
    $user = axWorkspace('a.test');
    $this->actingAs($user)->postJson('/api/v1/me/export')->assertStatus(202);
    $export = AccountExport::query()->sole();
    Storage::shouldReceive('disk')->andThrow(new RuntimeException('bucket down'));

    (new BuildAccountExport($export->id))->handle();

    expect($export->fresh()->status)->toBe('failed');
    Notification::assertNothingSent();
});

it('deletes the ZIP once the link has expired (PruneAccountExports, hourly)', function () {
    $export = axRun($this, axWorkspace('a.test'));
    (new PruneAccountExports)->handle();
    Storage::disk('s3')->assertExists($export->path);

    $this->travel(25)->hours();
    (new PruneAccountExports)->handle();
    Storage::disk('s3')->assertMissing($export->path);
    expect($export->fresh()->path)->toBeNull();
});
