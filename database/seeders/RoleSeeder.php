<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Internal staff roles for the Filament admin panel.
     * Creators never receive a role — roles are staff-only.
     */
    public function run(): void
    {
        foreach (['owner', 'support', 'finance'] as $role) {
            Role::findOrCreate($role);
        }
    }
}
