<?php

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Services\ModifierValidator;
use App\Services\ValidatedDrink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Fixture drink (base $6.00), groups in display order:
 *   Size      is_required, min_select 0, max 1 -> effective min 1. Medium (default), Large +50, XL (sold out)
 *   Flavours  not required, min_select 2, max 3 -> effective min 2. Peach, Lychee, Mango, Yuzu
 *   Toppings  not required, min_select 0, max 3. Pearls +75, Coconut +75, Pudding +100, Taro +75, Grass (sold out)
 * Another drink has an "Other" group with option "Foreign". Option names are unique,
 * so datasets can name options instead of using ids.
 * The smallest valid selection is Medium + Peach + Lychee.
 */
beforeEach(function () {
    $this->drink = MenuItem::factory()->create(['price_cents' => 600]);

    $groups = [
        'Size' => [['is_required' => true, 'min_select' => 0, 'max_select' => 1, 'sort_order' => 1], [
            ['Medium', 0, true, true], ['Large', 50, false, true], ['XL', 100, false, false],
        ]],
        'Flavours' => [['is_required' => false, 'min_select' => 2, 'max_select' => 3, 'sort_order' => 2], [
            ['Peach', 0, false, true], ['Lychee', 0, false, true], ['Mango', 0, false, true], ['Yuzu', 0, false, true],
        ]],
        'Toppings' => [['is_required' => false, 'min_select' => 0, 'max_select' => 3, 'sort_order' => 3], [
            ['Pearls', 75, false, true], ['Coconut', 75, false, true], ['Pudding', 100, false, true],
            ['Taro', 75, false, true], ['Grass', 75, false, false],
        ]],
    ];

    foreach ($groups as $name => [$attributes, $options]) {
        $group = ModifierGroup::factory()->create(['name_en' => $name, ...$attributes]);
        $this->drink->modifierGroups()->attach($group, ['sort_order' => $attributes['sort_order']]);

        foreach ($options as $i => [$optionName, $delta, $default, $available]) {
            ModifierOption::factory()->for($group)->create([
                'name_en' => $optionName,
                'price_delta_cents' => $delta,
                'is_default' => $default,
                'is_available' => $available,
                'sort_order' => $i + 1,
            ]);
        }
    }

    $other = MenuItem::factory()->create();
    $otherGroup = ModifierGroup::factory()->create(['name_en' => 'Other']);
    $other->modifierGroups()->attach($otherGroup);
    ModifierOption::factory()->for($otherGroup)->create(['name_en' => 'Foreign']);

    $this->validator = new ModifierValidator;
});

/**
 * @param  list<string>  $names
 * @return list<int>
 */
function optionIds(array $names): array
{
    return array_map(fn (string $name): int => ModifierOption::where('name_en', $name)->firstOrFail()->id, $names);
}

/**
 * Run the validator and return the error keys, with group ids replaced by group names
 * ("selected.Size"). Returns [] when validation passes.
 *
 * @param  array<array-key, mixed>  $ids
 * @return list<string>
 */
function errorKeys(MenuItem $drink, array $ids, int $quantity = 1): array
{
    try {
        (new ModifierValidator)->validate($drink, $ids, $quantity);

        return [];
    } catch (ValidationException $e) {
        $names = ModifierGroup::pluck('name_en', 'id');

        return array_map(
            fn (string $key): string => preg_replace_callback('/^selected\.(\d+)$/', fn ($m) => 'selected.'.$names[(int) $m[1]], $key) ?? $key,
            array_keys($e->errors()),
        );
    }
}

describe('effective minimum', function () {
    test('is the larger of min_select and 1 if required', function (bool $required, int $minSelect, int $expected) {
        $group = ModifierGroup::factory()->make(['is_required' => $required, 'min_select' => $minSelect]);

        expect($group->effectiveMin())->toBe($expected);
    })->with([
        'optional, min 0' => [false, 0, 0],
        'optional, min 2' => [false, 2, 2],
        'required, min 0' => [true, 0, 1],
        'required, min 1' => [true, 1, 1],
        'required, min 3' => [true, 3, 3],
    ]);
});

describe('valid selections', function () {
    test('are accepted', function (array $names, int $quantity) {
        expect(errorKeys($this->drink, optionIds($names), $quantity))->toBe([]);
    })->with([
        'smallest valid selection' => [['Medium', 'Peach', 'Lychee'], 1],
        'flavours exactly at max (3)' => [['Medium', 'Peach', 'Lychee', 'Mango'], 1],
        'toppings exactly at max (3)' => [['Large', 'Peach', 'Lychee', 'Pearls', 'Coconut', 'Pudding'], 1],
        'quantity exactly at min (1)' => [['Medium', 'Peach', 'Lychee'], 1],
        'quantity exactly at max (10)' => [['Medium', 'Peach', 'Lychee'], 10],
        'order of ids does not matter' => [['Lychee', 'Medium', 'Peach'], 2],
    ]);

    test('return sorted option ids, the quantity, and options in display order', function () {
        $ids = optionIds(['Pearls', 'Lychee', 'Large', 'Peach']);

        $result = $this->validator->validate($this->drink, $ids, 3);

        $sorted = $ids;
        sort($sorted);

        expect($result)->toBeInstanceOf(ValidatedDrink::class)
            ->and($result->optionIds)->toBe($sorted)
            ->and($result->quantity)->toBe(3)
            ->and($result->item->is($this->drink))->toBeTrue()
            ->and($result->options->pluck('name_en')->all())->toBe(['Large', 'Peach', 'Lychee', 'Pearls'])
            ->and($result->key())->toBe($this->drink->id.':'.implode(',', $sorted));
    });

    test('give the same key whatever order the ids arrive in', function () {
        $a = $this->validator->validate($this->drink, optionIds(['Medium', 'Peach', 'Lychee']), 1);
        $b = $this->validator->validate($this->drink, optionIds(['Lychee', 'Peach', 'Medium']), 5);

        expect($a->key())->toBe($b->key());
    });

    test('accept ids sent as numeric strings, as HTML forms send them', function () {
        $ids = array_map(strval(...), optionIds(['Medium', 'Peach', 'Lychee']));

        expect(errorKeys($this->drink, $ids))->toBe([]);
    });
});

describe('invalid selections', function () {
    test('are rejected with errors under the right key', function (array $names, array $extra, int $quantity, array $expectedKeys) {
        $ids = [...optionIds($names), ...$extra];

        expect(errorKeys($this->drink, $ids, $quantity))->toEqualCanonicalizing($expectedKeys);
    })->with([
        // Quantity
        'quantity 0 (below min)' => [['Medium', 'Peach', 'Lychee'], [], 0, ['quantity']],
        'quantity 11 (above max)' => [['Medium', 'Peach', 'Lychee'], [], 11, ['quantity']],
        'negative quantity' => [['Medium', 'Peach', 'Lychee'], [], -1, ['quantity']],
        // Ids that don't belong to any of this drink's groups
        'option id that does not exist' => [['Medium', 'Peach', 'Lychee'], [999999], 1, ['selected']],
        'option from another drink' => [['Medium', 'Peach', 'Lychee', 'Foreign'], [], 1, ['selected']],
        'non-numeric id' => [['Medium', 'Peach', 'Lychee'], ['abc'], 1, ['selected']],
        'decimal id' => [['Medium', 'Peach', 'Lychee'], ['1.5'], 1, ['selected']],
        'negative id' => [['Medium', 'Peach', 'Lychee'], [-3], 1, ['selected']],
        'zero id' => [['Medium', 'Peach', 'Lychee'], [0], 1, ['selected']],
        'nested array' => [['Medium', 'Peach', 'Lychee'], [['1']], 1, ['selected']],
        'same option twice' => [['Medium', 'Peach', 'Lychee', 'Peach'], [], 1, ['selected']],
        // Sold-out options
        'sold-out option in a single-select group' => [['XL', 'Peach', 'Lychee'], [], 1, ['selected.Size']],
        'sold-out option in a multi-select group' => [['Medium', 'Peach', 'Lychee', 'Grass'], [], 1, ['selected.Toppings']],
        // Per-group limits
        'required group with min_select 0: nothing chosen (effective min 1)' => [['Peach', 'Lychee'], [], 1, ['selected.Size']],
        'single-select group: two options' => [['Medium', 'Large', 'Peach', 'Lychee'], [], 1, ['selected.Size']],
        'min_select 2: one below the minimum' => [['Medium', 'Peach'], [], 1, ['selected.Flavours']],
        'min_select 2: nothing chosen' => [['Medium'], [], 1, ['selected.Flavours']],
        'max 3: one above the maximum' => [['Medium', 'Peach', 'Lychee', 'Mango', 'Yuzu'], [], 1, ['selected.Flavours']],
        'toppings max 3: four chosen' => [['Medium', 'Peach', 'Lychee', 'Pearls', 'Coconut', 'Pudding', 'Taro'], [], 1, ['selected.Toppings']],
        // Several problems at once are all reported
        'several problems' => [['Peach', 'Grass'], [999999], 0, ['quantity', 'selected', 'selected.Size', 'selected.Flavours', 'selected.Toppings']],
    ]);

    test('an id that names an option in a different group still counts toward its real group', function () {
        // Only the option ids matter; there are no group keys to tamper with.
        expect(errorKeys($this->drink, optionIds(['Medium', 'Large', 'Peach', 'Lychee'])))->toBe(['selected.Size']);
    });

    test('a sold-out drink is rejected', function () {
        $this->drink->update(['is_available' => false]);

        expect(errorKeys($this->drink->fresh(), optionIds(['Medium', 'Peach', 'Lychee'])))->toBe(['item']);
    });

    test('a drink in an inactive category is rejected', function () {
        $this->drink->category->update(['is_active' => false]);

        expect(errorKeys($this->drink->fresh(), optionIds(['Medium', 'Peach', 'Lychee'])))->toBe(['item']);
    });

    test('error messages name the group', function () {
        try {
            $this->validator->validate($this->drink, optionIds(['Peach']), 1);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $size = ModifierGroup::where('name_en', 'Size')->firstOrFail();
            $flavours = ModifierGroup::where('name_en', 'Flavours')->firstOrFail();

            expect($e->errors()['selected.'.$size->id])->toBe(['Choose an option for Size.'])
                ->and($e->errors()['selected.'.$flavours->id])->toBe(['Choose at least 2 options for Flavours.']);
        }
    });
});

test('a drink with no modifier groups only needs a valid quantity', function () {
    $plain = MenuItem::factory()->for(Category::factory())->create();

    expect(errorKeys($plain, [], 1))->toBe([])
        ->and(errorKeys($plain, [], 11))->toBe(['quantity']);
});
