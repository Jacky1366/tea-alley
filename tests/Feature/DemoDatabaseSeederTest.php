<?php

use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoCustomersSeeder;
use Database\Seeders\DemoOrdersSeeder;

/*
 * T3-5 (#53): DatabaseSeeder adds the demo customers and their orders in the local
 * environment only, so `migrate:fresh --seed` there gives the dashboard data. A file of its
 * own: DemoSeedersTest seeds the menu before every test, and DatabaseSeeder seeds it again.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 16:30:00', 'America/Vancouver'));
});

test('migrate:fresh --seed in the local environment seeds the demo data', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->seed(DatabaseSeeder::class);

    expect(User::query()->whereIn('email', DemoCustomersSeeder::emails())->count())->toBe(20)
        ->and(Order::query()->where('idempotency_key', 'like', DemoOrdersSeeder::KEY_PREFIX.'%')->count())->toBeGreaterThan(200);
});

test('other environments, like the test suite, don\'t seed it', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Order::count())->toBe(0)
        ->and(User::query()->whereIn('email', DemoCustomersSeeder::emails())->count())->toBe(0);
});
