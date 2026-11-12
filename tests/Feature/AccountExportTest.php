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
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\AccountExportReady;
use App\Support\Workspace;
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
    expect(array_keys($files))->toEqualCanonicalizing(['README.txt', 'account.json', 'workspace.json', 'channels.json', 'posts.json', 'approvals.json', 'files.json']);

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
    $user = axWorkspace('a.test');
    $key = $this->actingAs($user, 'web')->postJson('/api/v1/me/api-keys', ['name' => 'Claude', 'scopes' => ['read', 'draft', 'approve', 'publish']])->json('data.key');
    app('auth')->forgetGuards();

    bearer($this, $key)->postJson('/api/v1/me/export')->assertForbidden();
    expect(AccountExport::query()->count())->toBe(0);
    app('auth')->forgetGuards();
    $this->flushSession();
    $this->postJson('/api/v1/me/export')->assertUnauthorized();
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
