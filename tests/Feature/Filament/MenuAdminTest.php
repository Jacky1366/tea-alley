<?php

use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\MenuItems\MenuItemResource;
use App\Filament\Resources\MenuItems\Pages\CreateMenuItem;
use App\Filament\Resources\MenuItems\Pages\EditMenuItem;
use App\Filament\Resources\ModifierGroups\ModifierGroupResource;
use App\Filament\Resources\ModifierGroups\Pages\EditModifierGroup;
use App\Filament\Resources\ModifierGroups\RelationManagers\OptionsRelationManager;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Support\Money;
use Database\Seeders\MenuSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

/**
 * A small menu so list pages render real rows (category relation, counts, prices).
 */
function seedSmallMenu(): MenuItem
{
    $group = ModifierGroup::factory()->create();
    ModifierOption::factory()->for($group)->create();

    $item = MenuItem::factory()->create(['price_cents' => 625]);
    $item->modifierGroups()->attach($group);

    return $item;
}

describe('panel access', function () {
    test('guests are redirected to the login page', function () {
        $this->get('/admin')->assertRedirect(route('login'));
        $this->get(CategoryResource::getUrl('index'))->assertRedirect(route('login'));
    });

    test('non-manager roles are forbidden', function (string $role) {
        $user = userWithRole($role);

        $this->actingAs($user)->get('/admin')->assertForbidden();
        $this->actingAs($user)->get(MenuItemResource::getUrl('index'))->assertForbidden();
    })->with(['customer', 'barista']);

    test('managers and admins can load every resource list page', function (string $role, string $resource) {
        // The full menu has many rows per table, so strict mode would catch any lazy loading.
        $this->seed(MenuSeeder::class);

        $this->actingAs(userWithRole($role))
            ->get($resource::getUrl('index'))
            ->assertOk();
    })->with(['manager', 'admin'])->with([
        'categories' => CategoryResource::class,
        'menu items' => MenuItemResource::class,
        'modifier groups' => ModifierGroupResource::class,
    ]);

    test('the menu item list shows formatted prices', function () {
        $item = seedSmallMenu();

        $this->actingAs(userWithRole('manager'))
            ->get(MenuItemResource::getUrl('index'))
            ->assertSee('$6.25')
            ->assertSee($item->name_en);
    });
});

describe('policies', function () {
    test('only managers and admins may manage menu models', function (string $role, bool $allowed) {
        $user = userWithRole($role);

        foreach ([Category::class, MenuItem::class, ModifierGroup::class, ModifierOption::class] as $model) {
            expect($user->can('viewAny', $model))->toBe($allowed)
                ->and($user->can('create', $model))->toBe($allowed);
        }
    })->with([
        ['customer', false],
        ['barista', false],
        ['manager', true],
        ['admin', true],
    ]);
});

describe('money conversion', function () {
    test('Money::toCents rounds instead of truncating float error', function (string|int|float $dollars, int $cents) {
        expect(Money::toCents($dollars))->toBe($cents);
    })->with([
        // Floats where naive (int) ($x * 100) truncates: 0.29 * 100 = 28.999999999999996 -> 28.
        'float 0.29' => [0.29, 29],
        'float 19.99' => [19.99, 1999],
        'float 1.15' => [1.15, 115],
        'float 6.25' => [6.25, 625],
        'int 0' => [0, 0],
        // Form input arrives as strings.
        'string 0.29' => ['0.29', 29],
        'string 19.99' => ['19.99', 1999],
        'string 1.15' => ['1.15', 115],
        'string 6.25' => ['6.25', 625],
        'string 0' => ['0', 0],
        'int 5' => [5, 500],
    ]);

    test('Money formats cents for forms and display', function () {
        expect(Money::toDollars(625))->toBe('6.25')
            ->and(Money::toDollars(0))->toBe('0.00')
            ->and(Money::format(625))->toBe('$6.25')
            ->and(Money::format(1999))->toBe('$19.99');
    });

    test('creating a drink stores the dollar price as cents', function () {
        $this->actingAs(userWithRole('manager'));
        $category = Category::factory()->create();
        $group = ModifierGroup::factory()->create();

        Livewire::test(CreateMenuItem::class)
            ->fillForm([
                'category_id' => $category->id,
                'name_en' => 'Test Milk Tea',
                'name_zh' => '測試奶茶',
                'price_cents' => '6.25',
                'tax_category' => MenuItem::TAX_STANDARD,
                'prep_minutes' => 3,
                'sort_order' => 0,
                'is_available' => true,
                'modifierGroups' => [$group->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $item = MenuItem::where('name_en', 'Test Milk Tea')->firstOrFail();

        expect($item->price_cents)->toBe(625)
            ->and($item->modifierGroups()->pluck('modifier_groups.id')->all())->toBe([$group->id]);
    });

    test('the edit form shows the stored cents as dollars', function () {
        $this->actingAs(userWithRole('manager'));
        $item = MenuItem::factory()->create(['price_cents' => 625]);

        Livewire::test(EditMenuItem::class, ['record' => $item->getRouteKey()])
            ->assertFormSet(['price_cents' => '6.25']);
    });

    test('prices with more than two decimals are rejected', function () {
        $this->actingAs(userWithRole('manager'));

        Livewire::test(CreateMenuItem::class)
            ->fillForm([
                'category_id' => Category::factory()->create()->id,
                'name_en' => 'Bad Price',
                'name_zh' => '錯誤',
                'price_cents' => '6.255',
            ])
            ->call('create')
            ->assertHasFormErrors(['price_cents']);

        expect(MenuItem::count())->toBe(0);
    });

    test('creating a modifier option stores the dollar delta as cents', function () {
        $this->actingAs(userWithRole('admin'));
        $group = ModifierGroup::factory()->create();

        Livewire::test(OptionsRelationManager::class, [
            'ownerRecord' => $group,
            'pageClass' => EditModifierGroup::class,
        ])->callAction(TestAction::make('create')->table(), [
            'name_en' => 'Pearls',
            'name_zh' => '珍珠',
            'price_delta_cents' => '0.75',
            'sort_order' => 1,
            'is_default' => false,
            'is_available' => true,
        ])->assertHasNoFormErrors();

        expect($group->options()->firstOrFail()->price_delta_cents)->toBe(75);
    });
});

describe('deleting categories', function () {
    test('a category with drinks cannot be deleted from the table', function () {
        $this->actingAs(userWithRole('manager'));
        $category = MenuItem::factory()->create()->category;

        Livewire::test(ListCategories::class)
            ->callAction(TestAction::make('delete')->table($category))
            ->assertNotified('Category still has drinks');

        $this->assertModelExists($category);
    });

    test('a category with drinks cannot be bulk deleted', function () {
        $this->actingAs(userWithRole('manager'));
        $withDrinks = MenuItem::factory()->create()->category;
        $empty = Category::factory()->create();

        Livewire::test(ListCategories::class)
            ->selectTableRecords([$withDrinks->getKey(), $empty->getKey()])
            ->callAction(TestAction::make('delete')->table()->bulk())
            ->assertNotified('Category still has drinks');

        $this->assertModelExists($withDrinks);
        $this->assertModelExists($empty);
    });

    test('a category with drinks cannot be deleted from the edit page', function () {
        $this->actingAs(userWithRole('admin'));
        $category = MenuItem::factory()->create()->category;

        Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
            ->callAction('delete')
            ->assertNotified('Category still has drinks');

        $this->assertModelExists($category);
    });

    test('an empty category can be deleted', function () {
        $this->actingAs(userWithRole('manager'));
        $category = Category::factory()->create();

        Livewire::test(ListCategories::class)
            ->callAction(TestAction::make('delete')->table($category))
            ->assertNotNotified('Category still has drinks');

        $this->assertModelMissing($category);
    });
});
