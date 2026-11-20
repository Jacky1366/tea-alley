<?php

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use Database\Seeders\MenuSeeder;
use Illuminate\Support\Facades\DB;

/**
 * #61: 30 drinks (5 per category), and MenuSeeder can run again without duplicating or
 * overwriting anything.
 */

/**
 * @return array<string, int>
 */
function menuCounts(): array
{
    return [
        'categories' => Category::count(),
        'items' => MenuItem::count(),
        'groups' => ModifierGroup::count(),
        'options' => ModifierOption::count(),
        'pivot' => DB::table('menu_item_modifier_group')->count(),
    ];
}

/**
 * @return list<string>
 */
function groupNames(MenuItem $item): array
{
    return $item->modifierGroups()->orderByPivot('sort_order')->pluck('name_en')->all();
}

function seededDrink(string $name): MenuItem
{
    return MenuItem::where('name_en', $name)->firstOrFail();
}

test('a fresh seed has 6 categories of 5 drinks and the same modifier groups', function () {
    $this->seed(MenuSeeder::class);

    // 30 drinks x Size/Sugar/Ice + Toppings on the 25 non-sparkling + Milk on the 13 milk drinks.
    expect(menuCounts())->toBe([
        'categories' => 6, 'items' => 30, 'groups' => 5, 'options' => 18, 'pivot' => 90 + 25 + 13,
    ]);

    $perCategory = Category::withCount('menuItems')->pluck('menu_items_count', 'slug')->all();

    expect($perCategory)->toHaveCount(6)
        ->and(array_unique(array_values($perCategory)))->toBe([5]);

    $options = ModifierGroup::withCount('options')->pluck('options_count', 'name_en')->all();

    expect($options)->toBe(['Size' => 2, 'Sugar' => 5, 'Ice' => 5, 'Toppings' => 4, 'Milk' => 2]);
});

test('the new drinks come after the original three in each category', function () {
    $this->seed(MenuSeeder::class);

    $order = fn (string $slug) => Category::where('slug', $slug)->firstOrFail()
        ->menuItems()->orderBy('sort_order')->pluck('sort_order', 'name_en')->all();

    expect($order('milk-tea'))->toBe([
        'Classic Milk Tea' => 1, 'Brown Sugar Pearl Milk Tea' => 2, 'Taro Milk Tea' => 3,
        'Jasmine Milk Green Tea' => 4, 'Hojicha Milk Tea' => 5,
    ])->and($order('seasonal'))->toBe([
        'Strawberry Fresh Milk' => 1, 'Osmanthus Oolong Milk Tea' => 2, 'Winter Melon Lemonade' => 3,
        'Black Sesame Milk Tea' => 4, 'Yuzu Green Tea' => 5,
    ]);

    expect(seededDrink('Taro Purée Fresh Milk')->only(['name_zh', 'price_cents']))
        ->toBe(['name_zh' => '芋泥鮮奶', 'price_cents' => 725]);
});

test('new drinks follow the sparkling and milk rules', function (string $name, bool $sparkling, bool $milk) {
    $this->seed(MenuSeeder::class);

    $item = seededDrink($name);
    $expected = ['Size', 'Sugar', 'Ice', ...($sparkling ? [] : ['Toppings']), ...($milk ? ['Milk'] : [])];

    expect($item->tax_category)->toBe($sparkling ? MenuItem::TAX_CARBONATED : MenuItem::TAX_STANDARD)
        ->and(groupNames($item))->toBe($expected);
})->with([
    ['Jasmine Milk Green Tea', false, true],
    ['Hojicha Milk Tea', false, true],
    ['Kumquat Lemon Green Tea', false, false],
    ['Mixed Fruit Tea', false, false],
    ['Taro Purée Fresh Milk', false, true],
    ['Tieguanyin Fresh Milk', false, true],
    ['Kyoho Grape Sparkling Tea', true, false],
    ['Green Plum Sparkling Tea', true, false],
    ['Four Seasons Oolong Tea', false, false],
    ['Jin Xuan Oolong Tea', false, false],
    ['Black Sesame Milk Tea', false, true],
    ['Yuzu Green Tea', false, false],
]);

test('running the seeder twice creates no duplicates', function () {
    $this->seed(MenuSeeder::class);
    $first = menuCounts();

    $this->seed(MenuSeeder::class);

    expect(menuCounts())->toBe($first)
        ->and(Category::distinct()->count('slug'))->toBe(6)
        ->and(ModifierGroup::distinct()->count('name_en'))->toBe(5)
        ->and(MenuItem::query()->select('category_id', 'name_en')->distinct()->get())->toHaveCount(30)
        ->and(ModifierOption::query()->select('modifier_group_id', 'name_en')->distinct()->get())->toHaveCount(18);
});

test('a re-run keeps what a manager changed in admin', function () {
    $this->seed(MenuSeeder::class);

    $drink = seededDrink('Classic Milk Tea');
    $drink->update([
        'name_zh' => '招牌奶茶', 'price_cents' => 650, 'is_available' => false,
        'sort_order' => 9, 'photo_path' => 'menu/classic.jpg',
    ]);
    $drink->modifierGroups()->detach(ModifierGroup::where('name_en', 'Toppings')->value('id'));

    Category::where('slug', 'seasonal')->update(['name_en' => 'Winter Specials', 'is_active' => false]);
    ModifierGroup::where('name_en', 'Size')->update(['max_select' => 2]);
    ModifierOption::where('name_en', 'Large')->update(['price_delta_cents' => 75]);

    $this->seed(MenuSeeder::class);

    expect(seededDrink('Classic Milk Tea')->only(['name_zh', 'price_cents', 'is_available', 'sort_order', 'photo_path']))
        ->toBe(['name_zh' => '招牌奶茶', 'price_cents' => 650, 'is_available' => false, 'sort_order' => 9, 'photo_path' => 'menu/classic.jpg'])
        ->and(groupNames(seededDrink('Classic Milk Tea')))->toBe(['Size', 'Sugar', 'Ice', 'Milk'])
        ->and(Category::where('slug', 'seasonal')->firstOrFail()->only(['name_en', 'is_active']))
        ->toBe(['name_en' => 'Winter Specials', 'is_active' => false])
        ->and(ModifierGroup::where('name_en', 'Size')->value('max_select'))->toBe(2)
        ->and(ModifierOption::where('name_en', 'Large')->value('price_delta_cents'))->toBe(75)
        ->and(menuCounts()['items'])->toBe(30);
});

test('a re-run adds missing drinks to an existing 18-drink menu', function () {
    $this->seed(MenuSeeder::class);

    // The menu before #61: delete the 12 new drinks (sort_order 4 and 5).
    MenuItem::where('sort_order', '>', 3)->get()->each->delete();
    expect(MenuItem::count())->toBe(18);

    $this->seed(MenuSeeder::class);

    expect(menuCounts())->toBe([
        'categories' => 6, 'items' => 30, 'groups' => 5, 'options' => 18, 'pivot' => 90 + 25 + 13,
    ])->and(groupNames(seededDrink('Black Sesame Milk Tea')))->toBe(['Size', 'Sugar', 'Ice', 'Toppings', 'Milk']);
});
