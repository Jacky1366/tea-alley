<?php

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\User;
use Carbon\CarbonImmutable;
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

describe('online ordering banner', function () {
    beforeEach(function () {
        menuFixture();
        // Wednesday 11:00-21:00, cutoff 15: ordering until 20:45. Every other day stays closed.
        setStoreHours([3 => ['11:00:00', '21:00:00']]);
    });

    test('shows when ordering ends while it is open', function () {
        // Wed Nov 18, 2026 15:00 in Vancouver.
        $this->travelTo(CarbonImmutable::parse('2026-11-18 15:00:00', 'America/Vancouver'));

        $this->get('/')
            ->assertOk()
            ->assertSee('data-test="ordering-open"', escape: false)
            ->assertSee('Online ordering is open until 8:45 PM')
            ->assertSee('Oolong Classic');
    });

    test('shows the next opening in Vancouver time while closed', function () {
        // Wed Nov 18, 2026 21:00 in Vancouver. Thursday stays closed, so the next opening is Friday.
        $this->travelTo(CarbonImmutable::parse('2026-11-18 21:00:00', 'America/Vancouver'));
        setStoreHours([5 => ['11:00:00', '21:00:00']]);

        $this->get('/')
            ->assertOk()
            ->assertSee('data-test="ordering-closed"', escape: false)
            ->assertSee('Online ordering opens Fri 11:00 AM')
            ->assertSee('Oolong Classic');
    });

    test('says ordering is unavailable when no day is open', function () {
        setStoreHours(array_fill(0, 7, null));
        $this->travelTo(CarbonImmutable::parse('2026-11-18 15:00:00', 'America/Vancouver'));

        $this->get('/')
            ->assertOk()
            ->assertSee('data-test="ordering-unavailable"', escape: false)
            ->assertSee('Online ordering is currently unavailable')
            ->assertSee('Oolong Classic');
    });

    test('search and filters still work while ordering is closed', function () {
        setStoreHours(array_fill(0, 7, null));

        Livewire::test('pages::menu')
            ->set('search', 'mango')
            ->assertSee('Mango Splash')
            ->assertDontSee('Oolong Classic')
            ->set('search', '')
            ->call('selectCategory', 'teas')
            ->set('sort', 'price_desc')
            ->assertSeeInOrder(['Jasmine Dream', 'Oolong Classic'])
            ->assertSee('Online ordering is currently unavailable');
    });
});
