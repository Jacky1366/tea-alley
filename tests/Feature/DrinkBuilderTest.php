<?php

use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use Carbon\CarbonImmutable;
use Database\Seeders\MenuSeeder;
use Illuminate\Support\Js;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Fixture drink "Builder Tea" ($6.00):
 *   Size      required single-select: Medium (default), Large +50, XL (sold out)
 *   Toppings  optional, up to 3: Pearls +75 (default), Pudding +100 (default), Grass +75 (sold out, default)
 *   Milk      optional single-select: Oat +60, Soy +50
 * Another drink has an "Other" group with option "Foreign".
 *
 * Ordering is open every day 00:00-23:59 and the clock is Wed Nov 18, 2026 12:00 in Vancouver,
 * unless a test changes it.
 */
beforeEach(function () {
    setStoreHours(array_fill(0, 7, ['00:00:00', '23:59:00']));
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'America/Vancouver'));

    $this->drink = MenuItem::factory()->create(['name_en' => 'Builder Tea', 'name_zh' => '測試茶', 'price_cents' => 600]);

    $this->size = builderGroup($this->drink, 'Size', ['is_required' => true, 'max_select' => 1], 1, [
        ['Medium', 0, true, true], ['Large', 50, false, true], ['XL', 100, false, false],
    ]);
    $this->toppings = builderGroup($this->drink, 'Toppings', ['max_select' => 3], 2, [
        ['Pearls', 75, true, true], ['Pudding', 100, true, true], ['Grass', 75, true, false],
    ]);
    $this->milk = builderGroup($this->drink, 'Milk', ['max_select' => 1], 3, [
        ['Oat', 60, false, true], ['Soy', 50, false, true],
    ]);

    $other = MenuItem::factory()->create();
    builderGroup($other, 'Other', [], 1, [['Foreign', 0, false, true]]);
});

/**
 * @param  array<string, mixed>  $attributes
 * @param  list<array{0: string, 1: int, 2: bool, 3: bool}>  $options  [name, delta, default, available]
 */
function builderGroup(MenuItem $drink, string $name, array $attributes, int $pivotSort, array $options): ModifierGroup
{
    $group = ModifierGroup::factory()->create(['name_en' => $name, 'sort_order' => 0, ...$attributes]);
    $drink->modifierGroups()->attach($group, ['sort_order' => $pivotSort]);

    foreach ($options as $i => [$optionName, $delta, $default, $available]) {
        ModifierOption::factory()->for($group)->create([
            'name_en' => $optionName,
            'price_delta_cents' => $delta,
            'is_default' => $default,
            'is_available' => $available,
            'sort_order' => $i + 1,
        ]);
    }

    return $group;
}

function opt(string $name): int
{
    return ModifierOption::where('name_en', $name)->firstOrFail()->id;
}

function builder(MenuItem $drink): Testable
{
    return Livewire::test('pages::drink', ['item' => $drink]);
}

/**
 * Whether the "Add to order" button in the HTML has the disabled attribute.
 */
function addButtonDisabled(string $html): bool
{
    expect(preg_match('/<button\b[^>]*data-test="add-button"[^>]*>/', $html, $match))->toBe(1);

    return (bool) preg_match('/\sdisabled(\s|=|>)/', $match[0]);
}

describe('page', function () {
    test('guests can view a drink with its options, grouped and ordered', function () {
        $this->get(route('menu.show', $this->drink))
            ->assertOk()
            ->assertSee('Builder Tea')
            ->assertSee('測試茶')
            ->assertSee('$6.00')
            ->assertSeeInOrder(['Size', 'Medium', 'Large', 'XL', 'Toppings', 'Pearls', 'Pudding', 'Grass', 'Milk', 'Oat', 'Soy'])
            ->assertSee('+$0.50')
            ->assertSee('data-test="add-button"', escape: false);
    });

    test('groups are ordered by the pivot first, then by the group sort order', function () {
        $drink = MenuItem::factory()->create();
        builderGroup($drink, 'Second', ['sort_order' => 1], 2, [['B1', 0, false, true]]);
        builderGroup($drink, 'FirstByGroupSort', ['sort_order' => 1], 1, [['A1', 0, false, true]]);
        builderGroup($drink, 'ZeroByGroupSort', ['sort_order' => 0], 1, [['Z1', 0, false, true]]);

        $this->get(route('menu.show', $drink))
            ->assertOk()
            ->assertSeeInOrder(['ZeroByGroupSort', 'FirstByGroupSort', 'Second']);
    });

    test('a drink in an inactive category returns 404', function () {
        $this->drink->category->update(['is_active' => false]);

        $this->get(route('menu.show', $this->drink))->assertNotFound();
    });

    test('a drink that does not exist returns 404', function () {
        $this->get('/menu/999999')->assertNotFound();
    });

    test('a sold-out drink is shown, marked sold out, with the button disabled', function () {
        $this->drink->update(['is_available' => false]);

        $response = $this->get(route('menu.show', $this->drink))
            ->assertOk()
            ->assertSee('data-test="sold-out"', escape: false)
            ->assertSee('Sold out');

        expect(addButtonDisabled($response->getContent()))->toBeTrue();
    });

    test('the button is enabled while ordering is open', function () {
        expect(addButtonDisabled($this->get(route('menu.show', $this->drink))->getContent()))->toBeFalse();
    });

    test('the button is disabled and the banner shows while ordering is closed', function () {
        setStoreHours([3 => ['11:00:00', '21:00:00']]);
        $this->travelTo(CarbonImmutable::parse('2026-11-18 22:00:00', 'America/Vancouver'));

        $response = $this->get(route('menu.show', $this->drink))
            ->assertOk()
            ->assertSee('data-test="ordering-closed"', escape: false);

        expect(addButtonDisabled($response->getContent()))->toBeTrue();
    });

    test('the button is disabled when a required group has no available options', function () {
        ModifierOption::whereIn('id', [opt('Medium'), opt('Large')])->update(['is_available' => false]);

        $response = $this->get(route('menu.show', $this->drink))
            ->assertOk()
            ->assertSee('Some required options are sold out');

        expect(addButtonDisabled($response->getContent()))->toBeTrue();
    });

    test('the seeded menu renders every drink without lazy loading', function () {
        // Strict mode throws on lazy loading, so rendering each seeded drink proves the eager loads.
        $this->seed(MenuSeeder::class);

        MenuItem::query()->where('is_available', true)->get()->each(
            fn (MenuItem $drink) => $this->get(route('menu.show', $drink))->assertOk(),
        );
    });

    test('the menu links each drink to its builder page', function () {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('href="'.route('menu.show', $this->drink).'"', escape: false);
    });
});

describe('options UI', function () {
    test('available defaults are preselected; sold-out defaults are not', function () {
        builder($this->drink)
            ->assertSet('selected.'.$this->size->id, opt('Medium'))
            ->assertSet('selected.'.$this->toppings->id, [opt('Pearls'), opt('Pudding')])
            ->assertSet('selected.'.$this->milk->id, '')
            ->assertSet('quantity', 1);
    });

    test('defaults that are not the first option are preselected', function () {
        // Regression: filter() kept the original keys, so a default in the middle of the list was missed.
        $sugar = builderGroup($this->drink, 'Sugar', ['is_required' => true, 'max_select' => 1], 4, [
            ['0%', 0, false, true], ['50%', 0, false, true], ['100%', 0, true, true],
        ]);
        $extras = builderGroup($this->drink, 'Extras', ['max_select' => 3], 5, [
            ['E1', 0, false, true], ['E2', 0, true, true], ['E3', 0, false, true], ['E4', 0, true, true],
        ]);

        builder($this->drink)
            ->assertSet('selected.'.$sugar->id, opt('100%'))
            ->assertSet('selected.'.$extras->id, [opt('E2'), opt('E4')]);
    });

    test('a sold-out default in a single-select group is not preselected', function () {
        ModifierOption::whereKey(opt('Medium'))->update(['is_available' => false]);

        builder($this->drink)->assertSet('selected.'.$this->size->id, '');
    });

    test('with several defaults, a single-select group takes the first by sort order', function () {
        ModifierOption::whereKey(opt('Large'))->update(['is_default' => true]);
        ModifierOption::whereKey(opt('Medium'))->update(['sort_order' => 9]);

        builder($this->drink)->assertSet('selected.'.$this->size->id, opt('Large'));
    });

    test('a multi-select group never preselects more defaults than it allows', function () {
        builderGroup($this->drink, 'Two max', ['max_select' => 2], 4, [
            ['D1', 0, true, true], ['D2', 0, true, true], ['D3', 0, true, true],
        ]);

        $group = ModifierGroup::where('name_en', 'Two max')->firstOrFail();

        builder($this->drink)->assertSet('selected.'.$group->id, [opt('D1'), opt('D2')]);
    });

    test('"None" is offered only for single-select groups whose effective minimum is 0', function () {
        $notRequiredButMinOne = builderGroup($this->drink, 'MinOne', ['is_required' => false, 'min_select' => 1, 'max_select' => 1], 5, [
            ['M1', 0, false, true],
        ]);

        $this->get(route('menu.show', $this->drink))
            ->assertSee('data-test="option-none-'.$this->milk->id.'"', escape: false)
            ->assertDontSee('data-test="option-none-'.$this->size->id.'"', escape: false)
            ->assertDontSee('data-test="option-none-'.$this->toppings->id.'"', escape: false)
            ->assertDontSee('data-test="option-none-'.$notRequiredButMinOne->id.'"', escape: false);
    });

    test('single-select groups use radios and multi-select groups use checkboxes', function () {
        $html = $this->get(route('menu.show', $this->drink))->getContent();

        expect($html)->toMatch('/type="radio"[^>]*data-test="option-'.opt('Medium').'"/')
            ->and($html)->toMatch('/type="checkbox"[^>]*data-test="option-'.opt('Pearls').'"/');
    });

    test('sold-out options are shown but disabled', function () {
        $html = $this->get(route('menu.show', $this->drink))->getContent();

        expect(preg_match('/<input\b[^>]*data-test="option-'.opt('XL').'"[^>]*>/', $html, $match))->toBe(1)
            ->and($match[0])->toContain('disabled');
    });

    test('the starting total includes the preselected options', function () {
        // $6.00 + Pearls $0.75 + Pudding $1.00
        builder($this->drink)->assertSee('$7.75');
    });

    test('the price data for Alpine comes from the database', function () {
        builder($this->drink)
            ->assertSeeHtml('base: 600')
            ->assertSeeHtml(Js::from((object) [
                opt('Medium') => 0, opt('Large') => 50, opt('XL') => 100,
                opt('Pearls') => 75, opt('Pudding') => 100, opt('Grass') => 75,
                opt('Oat') => 60, opt('Soy') => 50,
            ])->toHtml());
    });
});

describe('submitting', function () {
    test('a valid selection passes server validation', function () {
        builder($this->drink)
            ->set('selected.'.$this->size->id, (string) opt('Large'))
            ->set('selected.'.$this->milk->id, (string) opt('Oat'))
            ->set('quantity', 3)
            ->call('addToOrder')
            ->assertHasNoErrors()
            ->assertSet('confirmed', true)
            ->assertSee('Your drink is ready to add.');
    });

    test('choosing "None" in an optional single-select group is valid', function () {
        builder($this->drink)
            ->set('selected.'.$this->milk->id, '')
            ->call('addToOrder')
            ->assertHasNoErrors()
            ->assertSet('confirmed', true);
    });

    test('errors show under their group', function () {
        builder($this->drink)
            ->set('selected.'.$this->size->id, '')
            ->call('addToOrder')
            ->assertHasErrors(['selected.'.$this->size->id])
            ->assertSee('data-test="error-group-'.$this->size->id.'"', escape: false)
            ->assertSee('Choose an option for Size.');
    });
});

describe('tampered requests are rejected', function () {
    test('tampered selections fail validation', function (Closure $tamper, string $errorKey) {
        $component = builder($this->drink);

        $tamper($component, $this);

        $component->call('addToOrder')
            ->assertHasErrors([str_replace(['{size}', '{toppings}'], [$this->size->id, $this->toppings->id], $errorKey)])
            ->assertSet('confirmed', false);
    })->with([
        'option from another drink, hidden under the size group key' => [
            fn ($c, $t) => $c->set('selected.'.$t->size->id, opt('Foreign')), 'selected',
        ],
        'option from another drink, added under a made-up group key' => [
            fn ($c, $t) => $c->set('selected.999', [opt('Foreign')]), 'selected',
        ],
        'option id that does not exist' => [
            fn ($c, $t) => $c->set('selected.'.$t->toppings->id, [999999]), 'selected',
        ],
        'sold-out option' => [
            fn ($c, $t) => $c->set('selected.'.$t->size->id, opt('XL')), 'selected.{size}',
        ],
        'sold-out option in a multi-select group' => [
            fn ($c, $t) => $c->set('selected.'.$t->toppings->id, [opt('Grass')]), 'selected.{toppings}',
        ],
        'two options in a single-select group' => [
            fn ($c, $t) => $c->set('selected.'.$t->size->id, [opt('Medium'), opt('Large')]), 'selected.{size}',
        ],
        'single-select option moved under another group key' => [
            fn ($c, $t) => $c->set('selected.'.$t->toppings->id, [opt('Large')]), 'selected.{size}',
        ],
        'duplicate option id' => [
            fn ($c, $t) => $c->set('selected.'.$t->toppings->id, [opt('Pearls'), opt('Pearls')]), 'selected',
        ],
        'non-numeric option id' => [
            fn ($c, $t) => $c->set('selected.'.$t->toppings->id, ['1 OR 1=1']), 'selected',
        ],
        'quantity 0' => [
            fn ($c, $t) => $c->set('quantity', 0), 'quantity',
        ],
        'quantity 11' => [
            fn ($c, $t) => $c->set('quantity', 11), 'quantity',
        ],
    ]);

    test('the drink id is locked', function () {
        $other = MenuItem::factory()->create();

        expect(fn () => builder($this->drink)->set('itemId', $other->id))
            ->toThrow(CannotUpdateLockedPropertyException::class);
    });

    test('a drink sold out after the page loaded is rejected on submit', function () {
        $component = builder($this->drink);

        $this->drink->update(['is_available' => false]);

        $component->call('addToOrder')
            ->assertHasErrors(['item'])
            ->assertSet('confirmed', false);
    });

    test('an option sold out after the page loaded is rejected on submit', function () {
        $component = builder($this->drink);

        ModifierOption::whereKey(opt('Pearls'))->update(['is_available' => false]);

        $component->call('addToOrder')
            ->assertHasErrors(['selected.'.$this->toppings->id])
            ->assertSet('confirmed', false);
    });
});
