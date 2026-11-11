<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoUserSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

test('new registrations get the customer role', function () {
    $this->post(route('register.store'), [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    expect(User::where('email', 'jane@example.com')->firstOrFail()->hasRole('customer'))->toBeTrue();
});

test('guests are redirected to login from the barista page', function () {
    $this->get(route('barista'))->assertRedirect(route('login'));
});

test('customers cannot access the barista page', function () {
    $this->seed(RoleSeeder::class);
    $customer = User::factory()->create()->assignRole('customer');

    $this->actingAs($customer)->get(route('barista'))->assertForbidden();
});

test('staff roles can access the barista page', function (string $role) {
    $this->seed(RoleSeeder::class);
    $user = User::factory()->create()->assignRole($role);

    $this->actingAs($user)
        ->get(route('barista'))
        ->assertOk()
        ->assertSee('Barista queue — coming in Stage 3');
})->with(['barista', 'manager', 'admin']);

test('the database seeder creates 4 roles and 4 verified demo users', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Role::pluck('name')->sort()->values()->all())
        ->toBe(['admin', 'barista', 'customer', 'manager']);

    foreach (['customer', 'barista', 'manager', 'admin'] as $role) {
        $user = User::where('email', "{$role}@tea-alley.test")->firstOrFail();

        expect($user->hasRole($role))->toBeTrue()
            ->and($user->hasVerifiedEmail())->toBeTrue()
            ->and(Hash::check('password', $user->password))->toBeTrue();
    }
});

test('demo users are not seeded in production', function () {
    $this->seed(RoleSeeder::class);
    app()->detectEnvironment(fn () => 'production');

    (new DemoUserSeeder)->run();

    expect(User::where('email', 'like', '%@tea-alley.test')->count())->toBe(0);
});
