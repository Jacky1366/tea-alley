<?php

use App\Filament\Resources\MenuItems\Pages\ListMenuItems;
use App\Filament\Resources\ModifierGroups\Pages\EditModifierGroup;
use App\Filament\Resources\ModifierGroups\RelationManagers\OptionsRelationManager;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tea = MenuItem::factory()->create(['name_en' => 'Toggle Tea']);
    $this->soldOutTea = MenuItem::factory()->unavailable()->create(['name_en' => 'Already Out Tea']);

    $this->group = ModifierGroup::factory()->create(['name_en' => 'Toppings']);
    $this->pearls = ModifierOption::factory()->for($this->group)->create(['name_en' => 'Pearls']);
    $this->pudding = ModifierOption::factory()->for($this->group)->create(['name_en' => 'Pudding', 'is_available' => false]);
});

function menuItemsTable(): Testable
{
    return Livewire::test(ListMenuItems::class);
}

function optionsTable(ModifierGroup $group): Testable
{
    return Livewire::test(OptionsRelationManager::class, [
        'ownerRecord' => $group,
        'pageClass' => EditModifierGroup::class,
    ]);
}

describe('menu items', function () {
    test('a manager or admin can mark a drink sold out and back from the list', function (string $role) {
        $this->actingAs(userWithRole($role));

        menuItemsTable()->call('updateTableColumnState', 'is_available', strval($this->tea->id), false);
        expect($this->tea->fresh()->is_available)->toBeFalse();

        menuItemsTable()->call('updateTableColumnState', 'is_available', strval($this->tea->id), true);
        expect($this->tea->fresh()->is_available)->toBeTrue();
    })->with(['manager', 'admin']);

    test('the toggle refuses the write without the update ability', function () {
        $this->actingAs(userWithRole('manager'));
        // Nobody can reach the panel without update today, so deny update alone to prove
        // the column checks the policy itself rather than relying on panel access.
        Gate::before(fn ($user, string $ability) => $ability === 'update' ? false : null);

        menuItemsTable()
            ->assertTableColumnExists('is_available', fn ($column) => $column->isDisabled(), $this->tea)
            ->call('updateTableColumnState', 'is_available', strval($this->tea->id), false);

        expect($this->tea->fresh()->is_available)->toBeTrue();
    });

    test('baristas and customers cannot open the list at all', function (string $role) {
        $this->actingAs(userWithRole($role));

        menuItemsTable()->assertForbidden();

        expect($this->tea->fresh()->is_available)->toBeTrue();
    })->with(['barista', 'customer']);

    test('the sold out filter lists only sold-out drinks', function () {
        $this->actingAs(userWithRole('manager'));

        menuItemsTable()
            ->assertCanSeeTableRecords([$this->tea, $this->soldOutTea])
            ->filterTable('sold_out')
            ->assertCanSeeTableRecords([$this->soldOutTea])
            ->assertCanNotSeeTableRecords([$this->tea]);
    });
});

describe('modifier options', function () {
    test('a manager or admin can mark an option sold out and back from the group\'s list', function (string $role) {
        $this->actingAs(userWithRole($role));

        optionsTable($this->group)->call('updateTableColumnState', 'is_available', strval($this->pearls->id), false);
        expect($this->pearls->fresh()->is_available)->toBeFalse();

        optionsTable($this->group)->call('updateTableColumnState', 'is_available', strval($this->pearls->id), true);
        expect($this->pearls->fresh()->is_available)->toBeTrue();
    })->with(['manager', 'admin']);

    test('the toggle refuses the write without the update ability', function () {
        $this->actingAs(userWithRole('manager'));
        Gate::before(fn ($user, string $ability) => $ability === 'update' ? false : null);

        optionsTable($this->group)
            ->assertTableColumnExists('is_available', fn ($column) => $column->isDisabled(), $this->pearls)
            ->call('updateTableColumnState', 'is_available', strval($this->pearls->id), false);

        expect($this->pearls->fresh()->is_available)->toBeTrue();
    });

    test('baristas and customers cannot open the group page', function (string $role) {
        $this->actingAs(userWithRole($role))
            ->get(EditModifierGroup::getUrl(['record' => $this->group]))
            ->assertForbidden();
    })->with(['barista', 'customer']);

    test('the sold out filter lists only sold-out options', function () {
        $this->actingAs(userWithRole('manager'));

        optionsTable($this->group)
            ->assertCanSeeTableRecords([$this->pearls, $this->pudding])
            ->filterTable('sold_out')
            ->assertCanSeeTableRecords([$this->pudding])
            ->assertCanNotSeeTableRecords([$this->pearls]);
    });
});
