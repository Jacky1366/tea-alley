<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DemoUserSeeder extends Seeder
{
    /**
     * One verified demo account per role, password "password". Never runs in production.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        foreach (RoleSeeder::ROLES as $role) {
            $user = User::updateOrCreate(
                ['email' => "{$role}@tea-alley.test"],
                ['name' => 'Demo '.ucfirst($role), 'password' => 'password'],
            );

            $user->forceFill(['email_verified_at' => now()])->save();
            $user->syncRoles([$role]);
        }
    }
}
