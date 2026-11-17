<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            MenuSeeder::class,
            StoreHoursSeeder::class,
            DemoUserSeeder::class,
        ]);

        // T3-5: the 20 spare customer accounts (DemoCustomersSeeder, which DemoOrdersSeeder
        // runs first) and two weeks of their orders, so the dashboard has data.
        // Local only; DemoOrdersSeeder refuses to run anywhere else.
        if (app()->environment('local')) {
            $this->call(DemoOrdersSeeder::class);
        }
    }
}
