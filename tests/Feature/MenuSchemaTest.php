<?php

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use Database\Seeders\DatabaseSeeder;

test('a category has many menu items', function () {
    $category = Category::factory()->create();
    MenuItem::factory()->count(3)->for($category)->create();

    expect($category->menuItems)->toHaveCount(3)
        ->each->toBeInstanceOf(MenuItem::class);
});

test('a menu item belongs to a category', function () {
    $category = Category::factory()->create();
    $item = MenuItem::factory()->for($category)->create();

    expect($item->category->is($category))->toBeTrue();
});

test('a menu item belongs to many modifier groups with pivot sort order', function () {
    $item = MenuItem::factory()->create();
    [$size, $sugar] = ModifierGroup::factory()->count(2)->create();

    $item->modifierGroups()->attach([
        $sugar->id => ['sort_order' => 2],
        $size->id => ['sort_order' => 1],
    ]);

    $groups = $item->modifierGroups;

    expect($groups)->toHaveCount(2)
        ->and($groups->first()->is($size))->toBeTrue()
        ->and($groups->first()->pivot->sort_order)->toBe(1)
        ->and($groups->last()->pivot->sort_order)->toBe(2);
});

test('a modifier group has many options', function () {
    $group = ModifierGroup::factory()->create();
    ModifierOption::factory()->count(4)->for($group)->create();

    expect($group->options)->toHaveCount(4)
        ->each->toBeInstanceOf(ModifierOption::class);
});

test('a modifier group belongs to many menu items', function () {
    $group = ModifierGroup::factory()->create();
    $items = MenuItem::factory()->count(2)->create();

    $group->menuItems()->attach($items->pluck('id'));

    expect($group->menuItems)->toHaveCount(2)
        ->and($group->menuItems->first()->pivot->sort_order)->toBe(0);
});

test('a modifier option belongs to a modifier group', function () {
    $group = ModifierGroup::factory()->create();
    $option = ModifierOption::factory()->for($group)->create();

    expect($option->modifierGroup->is($group))->toBeTrue();
});

test('boolean attributes are cast', function () {
    $item = MenuItem::factory()->create()->fresh();
    $option = ModifierOption::factory()->create()->fresh();

    expect($item->is_available)->toBeTrue()
        ->and($item->category->is_active)->toBeTrue()
        ->and($option->is_default)->toBeFalse()
        ->and($option->modifierGroup->is_required)->toBeFalse();
});

test('the database seeder builds the menu', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Category::count())->toBe(6)
        ->and(MenuItem::count())->toBeGreaterThanOrEqual(18)
        ->and(ModifierGroup::count())->toBe(5);

    $sparkling = Category::where('slug', 'sparkling')->firstOrFail();

    expect($sparkling->menuItems()->where('tax_category', '!=', MenuItem::TAX_CARBONATED)->count())->toBe(0)
        ->and(MenuItem::where('tax_category', MenuItem::TAX_CARBONATED)->count())->toBe($sparkling->menuItems()->count());
});

test('the seeded menu uses Taiwanese shop wording', function () {
    $this->seed(DatabaseSeeder::class);

    $group = fn (string $en) => ModifierGroup::where('name_en', $en)->firstOrFail();

    expect($group('Size')->name_zh)->toBe('容量')
        ->and($group('Milk')->name_zh)->toBe('換奶')
        ->and(Category::where('slug', 'pure-tea')->value('name_zh'))->toBe('純茶')
        // Least to most ice; Regular Ice stays the default.
        ->and($group('Ice')->options()->orderBy('sort_order')->pluck('name_zh')->all())->toBe(['去冰', '微冰', '少冰', '正常冰', '多冰'])
        ->and($group('Ice')->options()->where('is_default', true)->value('name_en'))->toBe('Regular Ice');
});
