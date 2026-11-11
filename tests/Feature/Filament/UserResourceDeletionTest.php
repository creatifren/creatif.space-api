<?php

use App\Enums\UserStatus;
use App\Models\User;
use App\Support\AccountDeletion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

function deletionAdmin(): User
{
    return User::findOrFail(User::factory()->create(['is_staff' => true, 'status' => UserStatus::Active])->id);
}

/** Filament → Users shows an account pending deletion and when it is purged. */
it('shows a pending account with its purge date in the list and on the edit page', function () {
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-11-12 09:00:00', 'UTC'));
    $pending = User::factory()->create(['name' => 'Pending Person']);
    AccountDeletion::request($pending);

    $this->actingAs(deletionAdmin())
        ->get('/admin/users')
        ->assertOk()
        ->assertSee('Pending Person')
        ->assertSee('pending_deletion')
        ->assertSee('Purge on');

    $this->actingAs(deletionAdmin())
        ->get("/admin/users/{$pending->ulid}/edit")
        ->assertOk()
        ->assertSee('Deletion requested')
        ->assertSee('Purged on 12 Dec 2026');

    Carbon::setTestNow();
});
