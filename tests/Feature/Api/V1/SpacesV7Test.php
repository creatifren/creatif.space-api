<?php

use App\Jobs\AddPublishedPostToSpaces;
use App\Models\BlogPost;
use App\Models\File;
use App\Models\Handle;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Space;
use App\Models\Subscription;
use App\Models\User;

/* v7 Spaces: Gallery or Blog, Link only by default, featured / hidden
   items, blog posts with client approval (never public before), and a
   social post's "Also add to". */

function spacesV7Owner(): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    Handle::factory()->for($user)->create(['name' => 'rizaltester']);

    return $user;
}

it('creates a Blog and a Gallery at their own address, Link only, downloads off unless asked', function () {
    $user = spacesV7Owner();

    $this->actingAs($user)->postJson('/api/v1/spaces', ['title' => 'Hotel Ombak News', 'type' => 'blog', 'slug' => 'hotel-ombak-news'])
        ->assertCreated()
        ->assertJsonPath('data.type', 'blog')
        ->assertJsonPath('data.slug', 'hotel-ombak-news')
        ->assertJsonPath('data.visibility', 'link');

    $this->actingAs($user)->postJson('/api/v1/spaces', ['title' => 'Press Kit', 'type' => 'gallery', 'allow_downloads' => true, 'visibility' => 'public'])
        ->assertCreated()
        ->assertJsonPath('data.visibility', 'public');
    expect(Space::query()->where('title', 'Press Kit')->first()->settings['allow_download'])->toBeTrue();

    $this->actingAs($user)->postJson('/api/v1/spaces', ['title' => 'Again', 'slug' => 'hotel-ombak-news'])->assertStatus(422);
});

it('never shows a blog post publicly before the client approves it', function () {
    $user = spacesV7Owner();
    $blog = Space::factory()->for($user)->published()->create(['type' => 'blog', 'slug' => 'news']);

    $id = $this->actingAs($user)->postJson("/api/v1/spaces/{$blog->ulid}/posts", [
        'title' => 'Ombak opens rooftop bar',
        'blocks' => [['type' => 'heading', 'text' => 'Opening 1 December'], ['type' => 'paragraph', 'text' => 'Something new…'], ['type' => 'divider']],
        'needs_approval' => true,
    ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

    $this->actingAs($user)->postJson("/api/v1/blog-posts/{$id}/publish")->assertStatus(422)->assertJsonPath('reason', 'needs_approval');
    $url = $this->actingAs($user)->postJson("/api/v1/blog-posts/{$id}/send-for-approval", ['approver_email' => 'client@example.test'])
        ->assertOk()->assertJsonPath('data.status', 'waiting')->json('data.approval_link.url');

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/profiles/rizaltester/spaces/news')->assertOk()->assertJsonCount(0, 'data.posts');
    $this->getJson('/api/v1/profiles/rizaltester/spaces/news/posts/ombak-opens-rooftop-bar')->assertNotFound();

    $token = basename($url);
    $this->getJson("/api/v1/a/{$token}")->assertOk()
        ->assertJsonPath('data.documents.0.title', 'Ombak opens rooftop bar')
        ->assertJsonPath('data.summary.waiting', 1);
    asApprover($this)->postJson("/api/v1/a/{$token}/documents/{$id}/decision", ['decision' => 'approve', 'note' => 'Lovely'])->assertOk();

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/profiles/rizaltester/spaces/news')->assertOk()->assertJsonPath('data.posts.0.slug', 'ombak-opens-rooftop-bar');
    $this->getJson('/api/v1/profiles/rizaltester/spaces/news/posts/ombak-opens-rooftop-bar')->assertOk()
        ->assertJsonPath('data.blocks.0.type', 'heading');
});

it('keeps featured items first and hidden items off the public gallery', function () {
    $user = spacesV7Owner();
    $gallery = Space::factory()->for($user)->published()->create(['slug' => 'portfolio']);
    $a = File::factory()->for($user)->create();
    $b = File::factory()->for($user)->create();
    $c = File::factory()->for($user)->create();

    $this->actingAs($user)->patchJson("/api/v1/spaces/{$gallery->ulid}", ['items' => [
        ['file_id' => $a->ulid, 'sort_order' => 1, 'title' => 'Latte art'],
        ['file_id' => $b->ulid, 'sort_order' => 2, 'title' => 'Sunset deck', 'featured' => true],
        ['file_id' => $c->ulid, 'sort_order' => 3, 'title' => 'Draft', 'hidden' => true],
    ]])->assertOk();

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/profiles/rizaltester/spaces/portfolio')->assertOk()
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.items.0.title', 'Sunset deck');
});

it('adds a published social post to its Gallery and Blog once', function () {
    $user = spacesV7Owner();
    $gallery = Space::factory()->for($user)->create();
    $blog = Space::factory()->for($user)->create(['type' => 'blog']);
    $file = File::factory()->for($user)->create();
    $post = SocialPost::factory()->for($user)->create(['caption' => "Rooftop opens\nSee you there", 'media' => [['url' => $file->url()]], 'status' => 'scheduled']);
    $post->targets()->create(['social_account_id' => SocialAccount::factory()->for($user)->create()->id, 'status' => 'pending']);

    $this->actingAs($user)->putJson("/api/v1/social-posts/{$post->ulid}/spaces", ['space_ids' => [$gallery->ulid, $blog->ulid]])
        ->assertOk()->assertJsonCount(2, 'data.space_ids');
    expect($gallery->items()->count())->toBe(0);

    $post->update(['status' => 'published']);
    (new AddPublishedPostToSpaces($post->id))->handle();

    expect($gallery->items()->count())->toBeGreaterThanOrEqual(0)
        ->and(BlogPost::query()->where('space_id', $blog->id)->value('title'))->toBe('Rooftop opens');
    $this->actingAs($user)->getJson("/api/v1/social-posts/{$post->ulid}")->assertOk()->assertJsonPath('data.also_add_to.0.added', true);
});
