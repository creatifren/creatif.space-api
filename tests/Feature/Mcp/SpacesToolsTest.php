<?php

use App\Models\File;
use App\Models\Handle;
use App\Models\McpAuditLog;
use App\Models\Space;
use App\Models\Subscription;
use App\Models\User;
use Tests\TestCase;

/*
 * Spaces for connected apps (list_spaces, create_space_from_files,
 * publish_space): Pro and Max only, drafts only without `publish`, and a
 * publish only with the preview's confirm_token.
 */

beforeEach(function () {
    ensurePassportKeys();
    config([
        'app.frontend_url' => 'https://creatif.space',
        'app.public_url' => 'https://creatif.space',
    ]);
});

function spacesKey(TestCase $test, User $user, array $scopes = ['read', 'draft', 'publish']): string
{
    $key = $test->actingAs($user, 'web')
        ->postJson('/api/v1/me/api-keys', ['name' => 'Spaces key', 'scopes' => $scopes])
        ->assertCreated()
        ->json('data.key');
    app('auth')->forgetGuards();

    return $key;
}

function spacesPro(?string $handle = 'studio'): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['status' => 'active']);
    if ($handle !== null) {
        Handle::factory()->for($user)->create(['name' => $handle]);
    }

    return $user;
}

describe('plan gate for connected apps', function () {
    it('answers the standard 402 plan_required on every Spaces route for a Free key', function (string $method, string $uri) {
        $free = User::factory()->create(); // no subscription = Free
        $space = Space::factory()->for($free)->create(['status' => 'draft']);
        $key = spacesKey($this, $free);

        bearer($this, $key)->json($method, str_replace('{space}', $space->ulid, $uri), ['title' => 'New'])
            ->assertStatus(402)
            ->assertJsonPath('reason', 'plan_required')
            ->assertJsonPath('code', 'plan_required')
            ->assertJsonPath('feature', 'spaces')
            ->assertJsonPath('required_plan', 'pro')
            ->assertJsonPath('billing_url', 'https://creatif.space/settings?section=subscription');

        expect(Space::query()->count())->toBe(1)
            ->and($space->refresh()->status->value)->toBe('draft');
    })->with([
        ['GET', '/api/v1/spaces'],
        ['GET', '/api/v1/spaces/{space}'],
        ['POST', '/api/v1/spaces'],
        ['GET', '/api/v1/spaces/{space}/publish-preview'],
        ['POST', '/api/v1/spaces/{space}/publish'],
    ]);

    it('still lists a Free user\'s Spaces in the app (cookie session)', function () {
        $free = User::factory()->create();
        Space::factory()->for($free)->create();

        $this->actingAs($free, 'web')->getJson('/api/v1/spaces')->assertOk()->assertJsonCount(1, 'data');
    });

    it('audits the refusal like any other token answer', function () {
        $free = User::factory()->create();
        $key = spacesKey($this, $free, ['read']);

        bearer($this, $key)->withHeaders(['X-MCP-Tool' => 'list_spaces'])->getJson('/api/v1/spaces')->assertStatus(402);

        expect(McpAuditLog::query()->sole()->only(['tool', 'route', 'status']))
            ->toBe(['tool' => 'list_spaces', 'route' => 'api.v1.spaces.index', 'status' => 402]);
    });
});

describe('list_spaces', function () {
    it('lists the caller\'s Spaces with their public address', function () {
        $pro = spacesPro('studio');
        Space::factory()->for($pro)->create(['title' => 'Wedding', 'slug' => 'wedding', 'status' => 'published']);
        Space::factory()->for(spacesPro('other'))->create();

        bearer($this, spacesKey($this, $pro, ['read']))->getJson('/api/v1/spaces')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Wedding')
            ->assertJsonPath('data.0.status', 'published')
            ->assertJsonPath('data.0.public_url', 'https://creatif.space/studio/wedding');
    });

    it('has no public address without a handle', function () {
        $pro = spacesPro(null);
        Space::factory()->for($pro)->create();

        bearer($this, spacesKey($this, $pro, ['read']))->getJson('/api/v1/spaces')
            ->assertOk()
            ->assertJsonPath('data.0.public_url', null);
    });
});

describe('create_space_from_files', function () {
    it('creates a Draft+Private Space holding the files, in the order given, with draft scope', function () {
        $pro = spacesPro();
        $a = File::factory()->for($pro)->create();
        $b = File::factory()->for($pro)->create();

        $res = bearer($this, spacesKey($this, $pro, ['draft']))
            ->postJson('/api/v1/spaces', ['title' => 'Shoot', 'file_ids' => [$b->ulid, $a->ulid, $b->ulid]])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.visibility', 'private')
            ->assertJsonCount(2, 'data.items');

        expect($res->json('data.items.0.file_id'))->toBe($b->ulid)
            ->and($res->json('data.items.1.file_id'))->toBe($a->ulid)
            ->and(Space::query()->sole()->published_at)->toBeNull();
    });

    it('makes nothing when a file is unknown, not ready or someone else\'s', function (string $which) {
        $pro = spacesPro();
        $mine = File::factory()->for($pro)->create();
        $bad = match ($which) {
            'unknown' => '01JUNKNOWNUNKNOWNUNKNOWNUN',
            'pending' => File::factory()->for($pro)->create(['status' => File::STATUS_PENDING])->ulid,
            'foreign' => File::factory()->for(User::factory()->create())->create()->ulid,
        };

        bearer($this, spacesKey($this, $pro, ['draft']))
            ->postJson('/api/v1/spaces', ['title' => 'Shoot', 'file_ids' => [$mine->ulid, $bad]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file_ids');

        expect(Space::query()->count())->toBe(0);
    })->with(['unknown', 'pending', 'foreign']);

    it('caps file_ids at 100', function () {
        $pro = spacesPro();

        bearer($this, spacesKey($this, $pro, ['draft']))
            ->postJson('/api/v1/spaces', ['title' => 'Big', 'file_ids' => array_fill(0, 101, '01JAAAAAAAAAAAAAAAAAAAAAAA')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file_ids');
    });

    it('needs draft scope', function () {
        $pro = spacesPro();

        bearer($this, spacesKey($this, $pro, ['read']))
            ->postJson('/api/v1/spaces', ['title' => 'Shoot'])
            ->assertForbidden()
            ->assertJsonPath('error', 'insufficient_scope');
    });
});

describe('publish_space', function () {
    it('previews what goes live, then publishes with the confirm_token', function () {
        $pro = spacesPro('studio');
        $space = Space::factory()->for($pro)->create(['title' => 'Wedding', 'slug' => 'wedding', 'status' => 'draft']);
        $space->items()->create(['file_id' => File::factory()->for($pro)->create()->id, 'sort_order' => 1]);
        $key = spacesKey($this, $pro);

        $preview = bearer($this, $key)->getJson("/api/v1/spaces/{$space->ulid}/publish-preview")
            ->assertOk()
            ->assertJsonPath('data.publishable', true)
            ->assertJsonPath('data.url', 'https://creatif.space/studio/wedding')
            ->assertJsonPath('data.items_count', 1)
            ->assertJsonPath('data.visibility', 'private')
            ->assertJsonPath('data.has_password', false)
            ->assertJsonPath('data.blocked', [])
            ->json('data');

        expect($preview['confirm_token'])->toBeString();
        expect($space->refresh()->status->value)->toBe('draft');

        bearer($this, $key)->postJson("/api/v1/spaces/{$space->ulid}/publish", ['confirm_token' => $preview['confirm_token']])
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.public_url', 'https://creatif.space/studio/wedding');
    });

    it('refuses a token publish without a confirm_token, or with a stale one', function () {
        $pro = spacesPro();
        $space = Space::factory()->for($pro)->create(['status' => 'draft', 'title' => 'Before']);
        $key = spacesKey($this, $pro);

        bearer($this, $key)->postJson("/api/v1/spaces/{$space->ulid}/publish")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'confirm_token_required');

        $token = bearer($this, $key)->getJson("/api/v1/spaces/{$space->ulid}/publish-preview")->json('data.confirm_token');
        $space->forceFill(['title' => 'After'])->save();

        bearer($this, $key)->postJson("/api/v1/spaces/{$space->ulid}/publish", ['confirm_token' => $token])
            ->assertStatus(409)
            ->assertJsonPath('code', 'preview_stale');
        bearer($this, $key)->postJson("/api/v1/spaces/{$space->ulid}/publish", ['confirm_token' => 'forged.token'])
            ->assertStatus(409);

        expect($space->refresh()->status->value)->toBe('draft');
    });

    it('does not accept another Space\'s confirm_token', function () {
        $pro = spacesPro();
        $one = Space::factory()->for($pro)->create(['status' => 'draft']);
        $two = Space::factory()->for($pro)->create(['status' => 'draft']);
        $key = spacesKey($this, $pro);

        $token = bearer($this, $key)->getJson("/api/v1/spaces/{$one->ulid}/publish-preview")->json('data.confirm_token');

        bearer($this, $key)->postJson("/api/v1/spaces/{$two->ulid}/publish", ['confirm_token' => $token])->assertStatus(409);
    });

    it('needs publish scope to publish; read scope may preview', function () {
        $pro = spacesPro();
        $space = Space::factory()->for($pro)->create(['status' => 'draft']);
        $key = spacesKey($this, $pro, ['read', 'draft']);

        $token = bearer($this, $key)->getJson("/api/v1/spaces/{$space->ulid}/publish-preview")->assertOk()->json('data.confirm_token');
        bearer($this, $key)->postJson("/api/v1/spaces/{$space->ulid}/publish", ['confirm_token' => $token])
            ->assertForbidden()
            ->assertJsonPath('error', 'insufficient_scope');
    });

    it('says when it is blocked or already live, and issues no token then', function () {
        $pro = spacesPro(null);
        $live = Space::factory()->for($pro)->create(['status' => 'published']);
        $key = spacesKey($this, $pro);

        bearer($this, $key)->getJson("/api/v1/spaces/{$live->ulid}/publish-preview")
            ->assertOk()
            ->assertJsonPath('data.publishable', false)
            ->assertJsonPath('data.url', null)
            ->assertJsonPath('data.warnings', ['no_handle', 'empty'])
            ->assertJsonMissingPath('data.confirm_token');
    });

    it('answers 404 for a Space in another workspace', function () {
        $pro = spacesPro();
        $theirs = Space::factory()->for(spacesPro('theirs'))->create(['status' => 'draft']);

        bearer($this, spacesKey($this, $pro))->getJson("/api/v1/spaces/{$theirs->ulid}/publish-preview")->assertNotFound();
    });

    it('leaves the app\'s own publish without a token', function () {
        $pro = spacesPro();
        $space = Space::factory()->for($pro)->create(['status' => 'draft']);

        $this->actingAs($pro, 'web')->postJson("/api/v1/spaces/{$space->ulid}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
    });
});
