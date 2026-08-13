<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class StaffSeeder extends Seeder
{
    /**
     * Local-only owner account for the Filament admin panel.
     * Change the password immediately on any shared environment.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $owner = User::query()->firstWhere('email', 'owner@creatif.space')
            ?? User::query()->make(['name' => 'Owner', 'email' => 'owner@creatif.space'])
                ->forceFill([
                    'password' => 'password',
                    'is_staff' => true,
                    'email_verified_at' => now(),
                ]);
        $owner->save();

        $owner->assignRole('owner');
    }
}
