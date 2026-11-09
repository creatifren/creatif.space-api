<?php

use App\Filament\Resources\Withdrawals\WithdrawalResource;
use App\Models\File;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * Soft launch (11 Nov): Transfers and payouts are cut from the public
 * surface. The models and tables stay for a later cleanup; only the doors
 * are closed.
 */
describe('cut from the launch', function () {
    it('no longer answers the Transfer routes', function (string $method, string $uri) {
        $this->actingAs(User::factory()->create())
            ->json($method, $uri)
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    })->with([
        'list' => ['GET', '/api/v1/transfers'],
        'received' => ['GET', '/api/v1/transfers/received'],
        'create' => ['POST', '/api/v1/transfers'],
        'update' => ['PATCH', '/api/v1/transfers/01HZXAAAAAAAAAAAAAAAAAAAAA'],
        'delete' => ['DELETE', '/api/v1/transfers/01HZXAAAAAAAAAAAAAAAAAAAAA'],
        'public page' => ['GET', '/api/v1/t/some-slug'],
        'public unlock' => ['POST', '/api/v1/t/some-slug/unlock'],
        'public download' => ['GET', '/api/v1/t/some-slug/files/01HZXAAAAAAAAAAAAAAAAAAAAA/download'],
    ]);

    it('no longer answers the payout routes', function (string $method, string $uri) {
        $this->actingAs(User::factory()->create())
            ->json($method, $uri)
            ->assertNotFound();
    })->with([
        'withdrawals' => ['GET', '/api/v1/me/withdrawals'],
        'withdraw' => ['POST', '/api/v1/me/withdrawals'],
        'payout account' => ['GET', '/api/v1/me/payout-account'],
        'save payout account' => ['PUT', '/api/v1/me/payout-account'],
    ]);

    it('keeps the payout queue out of the admin menu', function () {
        expect(WithdrawalResource::shouldRegisterNavigation())->toBeFalse();
    });

    it('still protects a file a stored transfer points at', function () {
        // The table outlives the routes, so the FK still has to hold.
        $user = User::factory()->create();
        $file = File::factory()->for($user)->create();
        Transfer::factory()->for($user)->create()->files()->attach($file->id);

        expect(fn () => $file->forceDelete())
            ->toThrow(QueryException::class);
    });
});

describe('kept for the launch', function () {
    it('still records a referral click', function () {
        $this->postJson('/api/v1/referrals/ANYCODE/click')->assertNoContent();
    });

    it('still serves the affiliate screen route', function () {
        // Hidden by a 404 until approved, but the route itself is there.
        expect(app('router')->has('api.v1.me.affiliate'))->toBeTrue()
            ->and(app('router')->has('api.v1.referrals.click'))->toBeTrue();
    });
});
