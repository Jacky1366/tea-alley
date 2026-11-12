<?php

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\User;
use Database\Seeders\MenuSeeder;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

/**
 * Two active categories with distinct, non-overlapping drink names so assertSee/assertDontSee are unambiguous.
 *
 * @return array{tea: Category, fruit: Category}
 */
function menuFixture(): array
{
    $tea = Category::factory()->create(['name_en' => 'Teas', 'name_zh' => '茶類', 'slug' => 'teas', 'sort_order' => 1]);
    $fruit = Category::factory()->create(['name_en' => 'Fruity', 'name_zh' => '水果', 'slug' => 'fruity', 'sort_order' => 2]);

    MenuItem::factory()->for($tea)->create(['name_en' => 'Oolong Classic', 'name_zh' => '烏龍經典', 'price_cents' => 500, 'sort_order' => 2]);
    MenuItem::factory()->for($tea)->create(['name_en' => 'Jasmine Dream', 'name_zh' => '茉莉夢', 'price_cents' => 700, 'sort_order' => 1]);
    MenuItem::factory()->for($fruit)->create(['name_en' => 'Mango Splash', 'name_zh' => '芒果飛濺', 'price_cents' => 600, 'sort_order' => 3]);

    return ['tea' => $tea, 'fruit' => $fruit];
}

test('guests can view the menu at /', function () {
    menuFixture();

    $this->get('/')
        ->assertOk()
        ->assertSee('Tea Alley 茶巷')
        ->assertSee('Oolong Classic')
        ->assertSee('烏龍經典')
        ->assertSee('$5.00')
        ->assertSee('Log in')
        ->assertSee('Register');
});

test('unavailable drinks are hidden', function () {
    $category = Category::factory()->create();
    MenuItem::factory()->for($category)->create(['name_en' => 'Visible Brew']);
    MenuItem::factory()->for($category)->unavailable()->create(['name_en' => 'Sold Out Brew']);

    Livewire::test('pages::menu')
        ->assertSee('Visible Brew')
        ->assertDontSee('Sold Out Brew');
});

test('inactive categories and their drinks are hidden', function () {
    $active = Category::factory()->create(['name_en' => 'Open Shelf']);
    $inactive = Category::factory()->inactive()->create(['name_en' => 'Hidden Shelf', 'slug' => 'hidden-shelf']);
    MenuItem::factory()->for($active)->create(['name_en' => 'Shown Drink']);
    MenuItem::factory()->for($inactive)->create(['name_en' => 'Secret Drink']);

    Livewire::test('pages::menu')
        ->assertSee('Open Shelf')
        ->assertDontSee('Hidden Shelf')
        ->assertSee('Shown Drink')
        ->assertDontSee('Secret Drink');

    // Even a direct link to the inactive category falls back to "All" without leaking its drinks.
    Livewire::withQueryParams(['category' => 'hidden-shelf'])
        ->test('pages::menu')
        ->assertSee('Shown Drink')
        ->assertDontSee('Secret Drink');
});

test('the category filter shows only that category', function () {
    menuFixture();

    Livewire::test('pages::menu')
        ->call('selectCategory', 'fruity')
        ->assertSet('category', 'fruity')
        ->assertSee('Mango Splash')
        ->assertDontSee('Oolong Classic')
        ->assertDontSee('Jasmine Dream')
        ->call('selectCategory', '')
        ->assertSee('Oolong Classic')
        ->assertSee('Mango Splash');
});

test('filters are read from the URL so links are shareable', function () {
    menuFixture();

    Livewire::withQueryParams(['category' => 'teas', 'q' => 'Jasmine', 'sort' => 'price_desc'])
        ->test('pages::menu')
        ->assertSet('category', 'teas')
        ->assertSet('search', 'Jasmine')
        ->assertSet('sort', 'price_desc')
        ->assertSee('Jasmine Dream')
        ->assertDontSee('Oolong Classic')
        ->assertDontSee('Mango Splash');
});

test('search matches English names', function () {
    menuFixture();

    Livewire::test('pages::menu')
        ->set('search', 'mango')
        ->assertSee('Mango Splash')
        ->assertDontSee('Oolong Classic')
        ->assertDontSee('Jasmine Dream');
});

test('search matches Chinese names', function () {
    menuFixture();

    Livewire::test('pages::menu')
        ->set('search', '茉莉')
        ->assertSee('Jasmine Dream')
        ->assertDontSee('Oolong Classic')
        ->assertDontSee('Mango Splash');
});

test('a search with no matches shows the empty state', function () {
    menuFixture();

    Livewire::test('pages::menu')
        ->set('search', 'nothing like this')
        ->assertSee('No drinks found');
});

test('drinks are sorted', function (string $sort, array $expectedOrder) {
    menuFixture();

    Livewire::test('pages::menu')
        ->set('sort', $sort)
        ->assertSeeInOrder($expectedOrder);
})->with([
    'popular (sort_order)' => ['popular', ['Jasmine Dream', 'Oolong Classic', 'Mango Splash']],
    'price low to high' => ['price_asc', ['Oolong Classic', 'Mango Splash', 'Jasmine Dream']],
    'price high to low' => ['price_desc', ['Jasmine Dream', 'Mango Splash', 'Oolong Classic']],
]);

test('an unknown sort value falls back to popular', function () {
    menuFixture();

    Livewire::withQueryParams(['sort' => 'drop table'])
        ->test('pages::menu')
        ->assertSeeInOrder(['Jasmine Dream', 'Oolong Classic', 'Mango Splash']);
});

test('the full seeded menu renders without lazy loading', function () {
    // Strict mode is on outside production, so any lazy load across these 18 drinks would throw.
    $this->seed(MenuSeeder::class);

    $this->get('/')
        ->assertOk()
        ->assertSee('Brown Sugar Pearl Milk Tea')
        ->assertSee('黑糖珍珠奶茶')
        ->assertSee('$6.95');

    Livewire::test('pages::menu')
        ->call('selectCategory', 'sparkling')
        ->assertSee('Lychee Sparkling Tea')
        ->assertDontSee('Classic Milk Tea');
});

describe('header links', function () {
    beforeEach(fn () => $this->seed(RoleSeeder::class));

    test('customers see the dashboard link but not admin', function () {
        $this->actingAs(User::factory()->create()->assignRole('customer'))
            ->get('/')
            ->assertSee('Dashboard')
            ->assertDontSee('/admin', escape: false)
            ->assertDontSee('Log in');
    });

    test('managers and admins also see the admin link', function (string $role) {
        $this->actingAs(User::factory()->create()->assignRole($role))
            ->get('/')
            ->assertSee('Dashboard')
            ->assertSee('/admin', escape: false);
    })->with(['manager', 'admin']);
});
