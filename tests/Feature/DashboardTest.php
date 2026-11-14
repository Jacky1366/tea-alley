<?php

use App\Models\MenuItem;
use App\Models\User;
use App\Services\Cart;
use App\Services\ModifierValidator;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;

/*
 * /dashboard is no longer a page: it sends each role to its own home, and it is Fortify's
 * default destination (config fortify.home), so login uses the same rule unless an
 * intended URL wins.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

dataset('role homes', [
    'customer' => ['customer', '/'],
    'barista' => ['barista', '/barista'],
    'manager' => ['manager', '/admin'],
    'admin' => ['admin', '/admin'],
]);

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('a user without a role goes to the menu', function () {
    $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertRedirect(url('/'));
});

test('/dashboard sends each role to its home', function (string $role, string $home) {
    $this->actingAs(userWithRole($role))->get(route('dashboard'))->assertRedirect(url($home));
})->with('role homes');

test('logging in lands each role on its home', function (string $role, string $home) {
    $user = userWithRole($role);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->get(route('dashboard'))->assertRedirect(url($home));
})->with('role homes');

test('an intended URL still wins over the role home', function (string $role) {
    setStoreHours(array_fill(0, 7, ['11:00:00', '22:00:00']));
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'America/Vancouver'));

    $drink = MenuItem::factory()->create();
    app(Cart::class)->add(app(ModifierValidator::class)->validate($drink, [], 1));
    $user = userWithRole($role);

    $this->get(route('checkout'))->assertRedirect(route('login'));

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('checkout'));
})->with(['customer', 'manager']);
