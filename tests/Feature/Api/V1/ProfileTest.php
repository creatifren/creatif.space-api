<?php

use App\Enums\UserStatus;
use App\Models\Handle;
use App\Models\Profile;
use App\Models\User;

describe('public profile', function () {
    it('shows the public page for a claimed handle', function () {
        $user = User::factory()->create(['name' => 'Rani Prameswari']);
        Handle::factory()->for($user)->create(['name' => 'rani']);
        Profile::factory()->for($user)->create([
            'headline' => 'Product & food photographer',
            'location' => 'Bandung',
        ]);

        $this->getJson('/api/v1/profiles/rani')
            ->assertOk()
            ->assertJsonPath('data.handle', 'rani')
            ->assertJsonPath('data.name', 'Rani Prameswari')
            ->assertJsonPath('data.location', 'Bandung')
            ->assertJsonMissingPath('data.email')
            ->assertJsonMissingPath('data.id');
    });

    it('404s for unknown, reserved, and unclaimed handles', function () {
        Handle::factory()->reserved()->create(['name' => 'admin']);

        $this->getJson('/api/v1/profiles/nobody')->assertNotFound();
        $this->getJson('/api/v1/profiles/admin')->assertNotFound();
    });

    it('404s when the owner is suspended', function () {
        $user = User::factory()->create();
        Handle::factory()->for($user)->create(['name' => 'rani']);
        $user->forceFill(['status' => UserStatus::Suspended])->save();

        $this->getJson('/api/v1/profiles/rani')->assertNotFound();
    });
});

describe('my profile', function () {
    it('returns and lazily creates the own profile', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/me/profile')
            ->assertOk()
            ->assertJsonPath('data.name', $user->name);

        expect($user->refresh()->profile)->not->toBeNull();
    });

    it('updates account and profile fields together', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/api/v1/me/profile', [
                'name' => 'Rani P.',
                'locale' => 'id',
                'headline' => 'Photographer',
                'socials' => ['instagram' => 'rani.foto'],
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Rani P.')
            ->assertJsonPath('data.locale', 'id')
            ->assertJsonPath('data.headline', 'Photographer')
            ->assertJsonPath('data.socials.instagram', 'rani.foto');
    });

    it('stores the full account-setting payload (mode, freelance, categories)', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/api/v1/me/profile', [
                'mode' => 'freelance',
                'freelance' => [
                    'status' => 'Taking projects (freelance)',
                    'show_status' => true,
                    'show_rate' => true,
                    'rates' => [
                        ['unit' => 'hour', 'amount' => 250000, 'on' => true],
                        ['unit' => 'day', 'amount' => 1500000, 'on' => true],
                    ],
                    'contact_whatsapp' => true,
                    'hire_button' => true,
                ],
                'categories' => ['Product', 'Interior', 'Editorial'],
                'cover_url' => 'https://example.com/cover.jpg',
            ])
            ->assertOk()
            ->assertJsonPath('data.mode', 'freelance')
            ->assertJsonPath('data.freelance.rates.0.amount', 250000)
            ->assertJsonPath('data.categories', ['Product', 'Interior', 'Editorial']);
    });

    it('caps categories at 3 and validates rate units', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/api/v1/me/profile', [
                'categories' => ['a', 'b', 'c', 'd'],
            ])
            ->assertUnprocessable();

        $this->actingAs($user)
            ->patchJson('/api/v1/me/profile', [
                'freelance' => ['rates' => [['unit' => 'fortnight', 'amount' => 1]]],
            ])
            ->assertUnprocessable();
    });

    /*
     * The Profile screen sends one merged patch for the whole page, and it
     * sends a rate row for every unit the owner has touched — amount still
     * null until they type a number. `required_with` rejected those rows,
     * so a half-filled rate table failed the save for every other field on
     * the profile too. Both spellings of "no price yet" are covered: the
     * key present and null, and the switch on with the price still blank.
     */
    it('accepts a rate row whose amount has not been typed yet', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/api/v1/me/profile', [
                'name' => 'Rani',
                'freelance' => [
                    'rates' => [
                        ['unit' => 'hour', 'amount' => null, 'on' => true],
                        ['unit' => 'day', 'amount' => null, 'on' => false],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Rani')
            ->assertJsonPath('data.freelance.rates.0.amount', null);

        // Still a number when it is one: nullable widened the gate, it did
        // not remove it.
        $this->actingAs($user)
            ->patchJson('/api/v1/me/profile', [
                'freelance' => ['rates' => [['unit' => 'hour', 'amount' => -1]]],
            ])
            ->assertUnprocessable();

        $this->actingAs($user)
            ->patchJson('/api/v1/me/profile', [
                'freelance' => ['rates' => [['unit' => 'hour', 'amount' => 'gratis']]],
            ])
            ->assertUnprocessable();
    });

    it('omits the freelance block publicly in portfolio mode but keeps the data', function () {
        $user = User::factory()->create();
        Handle::factory()->for($user)->create(['name' => 'rani']);
        Profile::factory()->for($user)->create([
            'mode' => 'portfolio',
            'freelance' => ['status' => 'Open to work', 'show_rate' => true],
        ]);

        $this->getJson('/api/v1/profiles/rani')
            ->assertOk()
            ->assertJsonPath('data.mode', 'portfolio')
            ->assertJsonPath('data.freelance', null);

        // The owner still sees their stored values ("kept for the trip back").
        $this->actingAs($user)
            ->getJson('/api/v1/me/profile')
            ->assertOk()
            ->assertJsonPath('data.freelance.status', 'Open to work');
    });

    it('exposes the freelance block publicly in freelance mode', function () {
        $user = User::factory()->create();
        Handle::factory()->for($user)->create(['name' => 'rani']);
        Profile::factory()->for($user)->create([
            'mode' => 'freelance',
            'freelance' => ['status' => 'Taking projects', 'hire_button' => true],
        ]);

        $this->getJson('/api/v1/profiles/rani')
            ->assertOk()
            ->assertJsonPath('data.mode', 'freelance')
            ->assertJsonPath('data.freelance.status', 'Taking projects');
    });

    it('saves and clears the avatar', function () {
        $user = User::factory()->create(['avatar_url' => 'https://lh3.googleusercontent.com/x']);

        $this->actingAs($user)
            ->patchJson('/api/v1/me/profile', ['avatar_url' => 'https://assets.creatif.space/f/abc.jpg'])
            ->assertOk()
            ->assertJsonPath('data.avatar_url', 'https://assets.creatif.space/f/abc.jpg');

        $this->actingAs($user)
            ->patchJson('/api/v1/me/profile', ['avatar_url' => null])
            ->assertOk()
            ->assertJsonPath('data.avatar_url', null);

        $this->actingAs($user)
            ->patchJson('/api/v1/me/profile', ['avatar_url' => 'not-a-url'])
            ->assertUnprocessable();
    });

    it('saves and validates the seo override', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/api/v1/me/profile', [
                'seo' => ['title' => 'Rani — Foto', 'description' => 'Wedding photographer.'],
            ])
            ->assertOk()
            ->assertJsonPath('data.seo.title', 'Rani — Foto');

        $this->actingAs($user)
            ->patchJson('/api/v1/me/profile', [
                'seo' => ['title' => str_repeat('a', 61)],
            ])
            ->assertUnprocessable();
    });

    it('validates locale and field lengths', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/api/v1/me/profile', ['locale' => 'fr'])
            ->assertUnprocessable();

        $this->actingAs($user)
            ->patchJson('/api/v1/me/profile', ['headline' => str_repeat('a', 121)])
            ->assertUnprocessable();
    });

    it('rejects guests', function () {
        $this->getJson('/api/v1/me/profile')->assertUnauthorized();
        $this->patchJson('/api/v1/me/profile', [])->assertUnauthorized();
    });
});
