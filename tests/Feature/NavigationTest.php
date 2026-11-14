<?php

use App\Models\MenuItem;
use App\Models\User;
use App\Services\Cart;
use App\Services\ModifierValidator;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

/**
 * The HTML of the public header's user menu.
 */
function userMenuHtml(string $page): string
{
    expect(preg_match('/data-test="user-menu".*?<\/form>/s', $page, $match))->toBe(1);

    return $match[0];
}

describe('public header', function () {
    test('guests see Log in and Register, and no user menu', function () {
        $this->get(route('home'))
            ->assertSee('Log in')
            ->assertSee('Register')
            ->assertDontSee('data-test="user-menu"', escape: false)
            ->assertDontSee('data-test="logout-button"', escape: false);
    });

    test('a logged-in user gets a menu with their name, email, My orders, Settings, and Log out', function () {
        $user = userWithRole('customer');
        $user->forceFill(['name' => 'Mei Lin', 'email' => 'mei@example.com'])->save();

        $page = $this->actingAs($user)->get(route('home'))
            ->assertDontSee('Log in')
            ->assertSee('data-test="user-menu-button"', escape: false)
            ->getContent();

        expect(userMenuHtml($page))
            ->toContain('Mei Lin')
            ->toContain('mei@example.com')
            ->toContain('href="'.route('orders.index').'"')
            ->toContain('href="'.route('profile.edit').'"')
            ->toContain('data-test="logout-button"');
    });

    test('Log out is a POST form with the CSRF token', function () {
        $page = $this->actingAs(userWithRole('customer'))->get(route('home'))->getContent();

        expect(userMenuHtml($page))
            ->toMatch('/<form method="POST" action="'.preg_quote(route('logout'), '/').'"/')
            ->toMatch('/name="_token" value="[^"]+"/');
    });

    test('the cart badge stays outside the menu', function () {
        $page = $this->actingAs(userWithRole('customer'))->get(route('home'))->getContent();

        expect($page)->toContain('data-test="cart-link"')
            ->and(userMenuHtml($page))->not->toContain('data-test="cart-link"');
    });

    test('Admin shows only for managers and admins', function (string $role, bool $sees) {
        $menu = userMenuHtml($this->actingAs(userWithRole($role))->get(route('home'))->getContent());

        expect(str_contains($menu, 'data-test="admin-link"'))->toBe($sees);
    })->with([
        'customer' => ['customer', false],
        'barista' => ['barista', false],
        'manager' => ['manager', true],
        'admin' => ['admin', true],
    ]);

    test('logging out from the header ends the session and empties the cart', function () {
        $user = userWithRole('customer');
        $this->actingAs($user);
        app(Cart::class)->add(app(ModifierValidator::class)->validate(MenuItem::factory()->create(), [], 2));

        expect(app(Cart::class)->count())->toBe(2);

        $page = $this->get(route('home'))->getContent();
        preg_match('/name="_token" value="([^"]+)"/', userMenuHtml($page), $token);

        $this->post(route('logout'), ['_token' => $token[1]])->assertRedirect('/');

        $this->assertGuest();
        expect(session()->has(Cart::SESSION_KEY))->toBeFalse()
            ->and(app(Cart::class)->count())->toBe(0);

        $this->get(route('home'))->assertSee('Log in');
    });
});

describe('sidebar layout', function () {
    test('shows the Tea Alley brand and Menu, My orders, and Settings', function () {
        $html = $this->actingAs(User::factory()->create())->get(route('profile.edit'))->assertOk()->getContent();

        expect($html)
            ->toContain('Tea Alley 茶巷')
            ->toContain('href="'.route('home').'"')
            ->toContain('href="'.route('orders.index').'"')
            ->toContain('href="'.route('profile.edit').'"')
            ->not->toContain('Repository')
            ->not->toContain('Documentation')
            ->not->toContain('href="'.route('dashboard').'"');
    });
});

test('no layout or shared component mentions Laravel or links to the starter kit', function () {
    $files = collect([
        ...File::allFiles(resource_path('views/layouts')),
        ...File::allFiles(resource_path('views/components')),
        resource_path('views/partials/head.blade.php'),
    ]);

    $offenders = $files
        ->map(fn ($file) => (string) $file)
        ->filter(fn (string $path) => preg_match('/Laravel|laravel\.com|livewire-starter-kit|github\.com\/laravel/', File::get($path)) === 1)
        ->map(fn (string $path) => str_replace(base_path().DIRECTORY_SEPARATOR, '', $path))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
