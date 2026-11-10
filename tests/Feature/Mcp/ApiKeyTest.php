<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(fn () => ensurePassportKeys());

it('creates a cs_live_ key shown once and stores only its hash', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'web')
        ->postJson('/api/v1/me/api-keys', ['name' => 'Claude Desktop', 'scopes' => ['read', 'draft']])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Claude Desktop')
        ->assertJsonPath('data.scopes', ['read', 'draft']);

    $key = $response->json('data.key');
    expect($key)->toStartWith('cs_live_')->toHaveLength(56);

    $row = PersonalAccessToken::query()->sole();
    expect($row->token)->toBe(hash('sha256', $key))->not->toContain('cs_live_');

    $this->getJson('/api/v1/me/api-keys')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonMissingPath('data.0.key')
        ->assertJsonPath('data.0.id', (string) $row->id);
});

it('validates name, scopes and expiry', function () {
    $this->actingAs(User::factory()->create(), 'web')
        ->postJson('/api/v1/me/api-keys', ['name' => '', 'scopes' => ['publish'], 'expires_in_days' => 7])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'scopes.0', 'expires_in_days']);
});

it('caps active keys', function () {
    config(['mcp.api_keys.max_active' => 2]);
    $user = User::factory()->create();
    $this->actingAs($user, 'web');

    $this->postJson('/api/v1/me/api-keys', ['name' => 'a', 'scopes' => ['read']])->assertCreated();
    $this->postJson('/api/v1/me/api-keys', ['name' => 'b', 'scopes' => ['read']])->assertCreated();
    $this->postJson('/api/v1/me/api-keys', ['name' => 'c', 'scopes' => ['read']])->assertUnprocessable();
});

it('authenticates the V1 API with the key, and stops once revoked', function () {
    $user = User::factory()->create();
    $key = $this->actingAs($user, 'web')
        ->postJson('/api/v1/me/api-keys', ['name' => 'Cursor', 'scopes' => ['read']])
        ->json('data.key');
    $id = PersonalAccessToken::query()->sole()->id;

    bearer($this, $key)->getJson('/api/v1/me')->assertOk();
    expect(PersonalAccessToken::query()->sole()->last_used_at)->not->toBeNull();

    app('auth')->forgetGuards();
    $this->actingAs($user, 'web')->deleteJson('/api/v1/me/api-keys/'.$id)->assertNoContent();

    bearer($this, $key)->getJson('/api/v1/me')->assertUnauthorized();
});

it('refuses an expired key', function () {
    $user = User::factory()->create();
    $key = $this->actingAs($user, 'web')
        ->postJson('/api/v1/me/api-keys', ['name' => 'x', 'scopes' => ['read'], 'expires_in_days' => 30])
        ->json('data.key');

    $this->travel(31)->days();

    bearer($this, $key)->getJson('/api/v1/me')->assertUnauthorized();
});

it('never lets a bearer token manage keys or connected apps', function () {
    $user = User::factory()->create();
    $key = $this->actingAs($user, 'web')
        ->postJson('/api/v1/me/api-keys', ['name' => 'x', 'scopes' => ['read', 'draft']])
        ->json('data.key');

    bearer($this, $key)->getJson('/api/v1/me/api-keys')->assertUnauthorized();
    bearer($this, $key)->postJson('/api/v1/me/api-keys', ['name' => 'y', 'scopes' => ['read']])->assertUnauthorized();
    bearer($this, $key)->getJson('/api/v1/me/connected-apps')->assertUnauthorized();
});

it('cannot revoke someone else\'s key', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner, 'web')->postJson('/api/v1/me/api-keys', ['name' => 'x', 'scopes' => ['read']]);
    $id = PersonalAccessToken::query()->sole()->id;

    app('auth')->forgetGuards();
    $this->actingAs(User::factory()->create(), 'web')->deleteJson('/api/v1/me/api-keys/'.$id)->assertNotFound();
    expect(PersonalAccessToken::query()->count())->toBe(1);
});
