<?php

use App\Http\Middleware\ScopeToWorkspace;
use App\Models\Contracts\WorkspaceOwned;
use App\Models\File;
use App\Models\FileVersion;
use App\Models\Folder;
use App\Models\InsightReport;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialComment;
use App\Models\SocialConversation;
use App\Models\SocialPlanLink;
use App\Models\SocialPost;
use App\Models\SocialPostComment;
use App\Models\Space;
use App\Models\TeamMember;
use App\Models\User;
use App\Support\Workspace;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
 * Route inventory: every authenticated route that takes an id.
 *
 * An id from another workspace must answer 404 — the answer an id nobody
 * has gets — never 403, which confirms the id exists (QA P2: GET
 * /social-posts/{id} answered 403 "This action is unauthorized."). The rule
 * lives in one place, ScopeToWorkspace; these tests walk the route table, so
 * a new route is covered the moment it is declared:
 *
 *  - its model must implement WorkspaceOwned (or the parameter is listed in
 *    WSI_NOT_WORKSPACE_MODELS with the reason), and
 *  - calling it with another workspace's id must answer 404, as that
 *    workspace's owner and as a team member. A model with no fixture below
 *    fails the test until one is added.
 */

/** Route parameters that are not workspace-owned models, and why. */
const WSI_NOT_WORKSPACE_MODELS = [
    // Personal tokens: deleted from the caller's own tokens only (404 otherwise).
    'apiKey' => 'personal API key id',
    // OAuth clients: revoked from the caller's own grants only (404 otherwise).
    'clientId' => 'OAuth client id',
    // Trash: looked up only in the caller's workspace trash (404 otherwise).
    'ulid' => 'trashed file ulid',
    // Engage feed (MCP): a typed id, dm_<ulid> or comment_<ulid>, looked up
    // only in the caller's workspace by InboxFeedController (404 otherwise).
    'item' => 'Engage feed id (dm_ / comment_)',
    // Approver side (MCP): a post someone else sent the caller, reached only
    // through a live link addressed to the caller's verified email (`link`;
    // none or unknown answers 404).
    'post' => 'post on an approval link addressed to the caller',
];

beforeEach(function () {
    Workspace::forget();
    config([
        // The inbox answers 404 while it is off, which would pass for the
        // wrong reason: switch it on so its routes are really checked.
        'services.inbox.enabled' => true,
        'ai.caption.enabled' => true,
    ]);
});

/** @return list<RoutingRoute> authenticated /api/v1 routes that take at least one parameter */
function wsiRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $r) => str_starts_with($r->uri(), 'api/v1/')
            && $r->parameterNames() !== []
            && collect($r->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'auth:')))
        ->values()
        ->all();
}

/** @return array<string, class-string> parameter name => bound model class */
function wsiBound(RoutingRoute $route): array
{
    $bound = [];
    foreach ($route->signatureParameters(['subClass' => UrlRoutable::class]) as $parameter) {
        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType) {
            $bound[$parameter->getName()] = $type->getName();
        }
    }

    return $bound;
}

/** An owner on Team with an active member of theirs ($role). @return array{User, User} */
function wsiTeam(string $role = 'editor'): array
{
    $owner = User::factory()->create();
    $subscription = $owner->subscriptions()->create([
        'plan_id' => Plan::query()->where('key', 'team')->value('id'),
        'billing_period' => 'monthly', 'seats' => 3, 'status' => 'active',
    ]);
    $subscription->forceFill(['current_period_start' => now()->subDays(10), 'current_period_end' => now()->addDays(20)])->save();

    $member = User::factory()->create();
    TeamMember::factory()->create([
        'owner_id' => $owner->id, 'member_id' => $member->id, 'email' => $member->email,
        'role' => $role, 'status' => 'active', 'joined_at' => now(),
    ]);
    Workspace::forget();

    return [$owner, $member];
}

/**
 * One row of $class in $owner's workspace. A new WorkspaceOwned model
 * reached by a route needs a line here.
 *
 * @param  array<string, WorkspaceOwned>  $made  rows already made for this route (a version needs its file)
 */
function wsiMake(string $class, User $owner, array $made): WorkspaceOwned
{
    $account = fn () => SocialAccount::factory()->for($owner)->create([
        'platform' => 'instagram', 'provider' => 'outstand', 'username' => 'studio',
    ]);

    return match ($class) {
        SocialPost::class => SocialPost::factory()->for($owner)->create(['provider_post_id' => null]),
        SocialAccount::class => $account(),
        SocialPostComment::class => SocialPost::factory()->for($owner)->create()
            ->comments()->create(['author_name' => 'Andi', 'role' => 'COMMENTER', 'body' => 'Open']),
        SocialApprovalLink::class => SocialApprovalLink::create(['user_id' => $owner->id, 'title' => 'Nov', 'client_name' => 'Andi']),
        SocialPlanLink::class => SocialPlanLink::query()->create([
            'user_id' => $owner->id, 'title' => 'Plan', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'account_ids' => [],
        ]),
        SocialConversation::class => SocialConversation::query()->create([
            'user_id' => $owner->id, 'social_account_id' => $account()->id, 'provider' => 'outstand',
            'provider_conversation_id' => 'conv_'.str()->random(8), 'network' => 'instagram',
            'contact_id' => 'ig_contact', 'contact_username' => 'ana', 'contact_name' => 'Ana',
            'last_message_at' => now(), 'last_inbound_at' => now(), 'unread_count' => 1, 'status' => 'open',
        ]),
        SocialComment::class => SocialComment::query()->create([
            'user_id' => $owner->id, 'social_account_id' => $account()->id, 'provider' => 'outstand',
            'provider_post_id' => 'post_enc_1', 'platform_post_id' => '1789', 'comment_id' => 'c_'.str()->random(8),
            'author_id' => 'ig_fan', 'author_username' => 'fan', 'text' => 'Love this!', 'network' => 'instagram',
            'commented_at' => now(),
        ]),
        Folder::class => Folder::create(['user_id' => $owner->id, 'name' => 'Theirs']),
        File::class => File::factory()->for($owner)->create(),
        FileVersion::class => FileVersion::create([
            'file_id' => ($made['file'] ?? File::factory()->for($owner)->create())->id,
            'number' => 1, 'path' => 'versions/'.str()->ulid().'/v1.jpg', 'user_id' => $owner->id,
        ]),
        Space::class => Space::factory()->for($owner)->create(),
        TeamMember::class => TeamMember::factory()->create(['owner_id' => $owner->id]),
        InsightReport::class => InsightReport::query()->create([
            'user_id' => $owner->id, 'owner_id' => $owner->id, 'client_id' => $owner->ulid,
            'format' => 'deck', 'locale' => 'en', 'period_from' => '2026-10-01', 'period_to' => '2026-10-31',
            'options' => ['period_kind' => 'month', 'channel_ids' => [], 'show_logo' => false, 'hide_branding' => false, 'next_month_notes' => null],
            'status' => 'queued', 'file_name' => 'theirs.pdf',
        ]),
        default => throw new RuntimeException("No fixture for {$class}: add one to wsiMake() so its routes are checked."),
    };
}

/** A value for a parameter that is not a model, belonging to $owner. */
function wsiValue(string $name, User $owner): string
{
    return match ($name) {
        'ulid' => tap(File::factory()->for($owner)->create(), fn (File $f) => $f->delete())->ulid,
        'apiKey' => (string) $owner->createToken('theirs')->accessToken->getKey(),
        'clientId' => (string) str()->uuid(),
        'item' => 'dm_'.wsiMake(SocialConversation::class, $owner, [])->getRouteKey(),
        'post' => wsiMake(SocialPost::class, $owner, [])->getRouteKey(),
        default => throw new RuntimeException("No value for {$name}."),
    };
}

/** The URL for $route with every parameter taken from $other's workspace. */
function wsiUrl(RoutingRoute $route, User $other): string
{
    $bound = wsiBound($route);
    $made = [];
    $values = [];
    foreach ($route->parameterNames() as $name) {
        if (isset($bound[$name])) {
            $made[$name] = wsiMake($bound[$name], $other, $made);
            $values[$name] = $made[$name]->getRouteKey();
        } else {
            $values[$name] = wsiValue($name, $other);
        }
    }

    $uri = $route->uri();
    foreach ($values as $name => $value) {
        $uri = str_replace(['{'.$name.'}', '{'.$name.'?}'], (string) $value, $uri);
    }

    return '/'.$uri;
}

it('scopes every route-bound id to the workspace', function () {
    $routes = wsiRoutes();
    expect($routes)->not->toBeEmpty();

    $problems = [];
    foreach ($routes as $route) {
        $bound = wsiBound($route);
        foreach ($route->parameterNames() as $name) {
            $class = $bound[$name] ?? null;
            if ($class === null) {
                if (! array_key_exists($name, WSI_NOT_WORKSPACE_MODELS)) {
                    $problems[] = "{$route->uri()}: {{$name}} is not a bound model — bind a WorkspaceOwned model or list it in WSI_NOT_WORKSPACE_MODELS with the reason";
                }

                continue;
            }
            if (! is_subclass_of($class, WorkspaceOwned::class)) {
                $problems[] = "{$route->uri()}: {{$name}} is {$class}, which does not implement WorkspaceOwned";
            }
            if (! in_array(ScopeToWorkspace::class, $route->gatherMiddleware(), true)) {
                $problems[] = "{$route->uri()}: takes a workspace id but does not run ScopeToWorkspace";
            }
        }
    }

    expect($problems)->toBe([]);
});

it('answers 404 — never 403 — for another workspace\'s id on every route', function (string $as) {
    [$owner, $member] = wsiTeam('editor');
    $caller = $as === 'owner' ? $owner : $member;
    [$theirs] = wsiTeam('editor');

    $answers = [];
    foreach (wsiRoutes() as $route) {
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $url = wsiUrl($route, $theirs);
            $status = $this->actingAs($caller)->json($method, $url)->getStatusCode();
            if ($status !== 404) {
                $answers[] = "{$method} {$url} ({$route->getName()}) answered {$status}";
            }
        }
    }

    expect($answers)->toBe([]);
})->with(['owner', 'member']);

it('answers another workspace\'s post exactly like a post that does not exist', function () {
    config(['app.debug' => false]); // as in production: no trace to tell them apart
    $mine = User::factory()->create();
    $theirs = SocialPost::factory()->for(User::factory()->create())->create();

    $foreign = $this->actingAs($mine)->getJson("/api/v1/social-posts/{$theirs->ulid}")->assertNotFound();
    $missing = $this->actingAs($mine)->getJson('/api/v1/social-posts/'.str()->ulid())->assertNotFound();

    // Same body shape, the id aside: nothing tells the two apart.
    expect(str_replace($theirs->ulid, 'ID', (string) $foreign->getContent()))
        ->toBe(str_replace(
            (string) str($missing->getContent())->match('/[0-9A-HJKMNP-TV-Z]{26}/i'),
            'ID',
            (string) $missing->getContent(),
        ));
});

it('the QA case: show, update, delete, send and schedule a post in another workspace answer 404', function () {
    $mine = User::factory()->create();
    $post = SocialPost::factory()->for(User::factory()->create())->create(['provider_post_id' => null]);

    $this->actingAs($mine)->getJson("/api/v1/social-posts/{$post->ulid}")->assertNotFound();
    $this->actingAs($mine)->patchJson("/api/v1/social-posts/{$post->ulid}", ['caption' => 'x'])->assertNotFound();
    $this->actingAs($mine)->patchJson("/api/v1/social-posts/{$post->ulid}", ['scheduled_at' => now()->addDay()->toIso8601String()])->assertNotFound();
    $this->actingAs($mine)->getJson("/api/v1/social-posts/{$post->ulid}/send-preview")->assertNotFound();
    $this->actingAs($mine)->postJson("/api/v1/social-posts/{$post->ulid}/send")->assertNotFound();
    $this->actingAs($mine)->deleteJson("/api/v1/social-posts/{$post->ulid}/draft")->assertNotFound();
    $this->actingAs($mine)->deleteJson("/api/v1/social-posts/{$post->ulid}")->assertNotFound();

    expect($post->fresh())->not->toBeNull();
});

it('keeps the rules inside the workspace: a member reads, a viewer gets 403 on writes', function () {
    [$owner, $viewer] = wsiTeam('viewer');
    $space = Space::factory()->for($owner)->create();
    $file = File::factory()->for($owner)->create();

    $this->actingAs($viewer)->getJson("/api/v1/files/{$file->ulid}")->assertOk();
    $this->actingAs($viewer)->deleteJson("/api/v1/files/{$file->ulid}")->assertForbidden();
    $this->actingAs($viewer)->patchJson("/api/v1/spaces/{$space->ulid}", ['title' => 'x'])->assertForbidden();

    // A trashed file of the owner's: the viewer may not act on it (403);
    // another workspace's trashed file is simply not there (404).
    $trashed = tap(File::factory()->for($owner)->create(), fn (File $f) => $f->delete());
    $this->actingAs($viewer)->postJson("/api/v1/files/trash/{$trashed->ulid}/restore")->assertForbidden();
    [$other] = wsiTeam('editor');
    $theirs = tap(File::factory()->for($other)->create(), fn (File $f) => $f->delete());
    $this->actingAs($viewer)->postJson("/api/v1/files/trash/{$theirs->ulid}/restore")->assertNotFound();
});
