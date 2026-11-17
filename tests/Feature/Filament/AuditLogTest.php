<?php

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\MenuItems\Pages\EditMenuItem;
use App\Filament\Resources\MenuItems\Pages\ListMenuItems;
use App\Filament\Resources\ModifierGroups\Pages\EditModifierGroup;
use App\Filament\Resources\ModifierGroups\RelationManagers\OptionsRelationManager;
use App\Models\AuditLog;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Support\AuditChanges;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/*
 * T2-8 (#47). The clock is Wed Nov 18, 2026 14:05 in Vancouver.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-18 14:05:00', 'America/Vancouver'));

    $this->tea = MenuItem::factory()->create(['name_en' => 'Classic Milk Tea', 'price_cents' => 595]);
    $this->toppings = ModifierGroup::factory()->create(['name_en' => 'Toppings']);
    $this->pearls = ModifierOption::factory()->for($this->toppings)->create(['name_en' => 'Pearls', 'price_delta_cents' => 75]);
});

describe('what is recorded', function () {
    test('a manager changing a price on the edit page: who, when, and from how much to how much', function () {
        $manager = userWithRole('manager');
        $manager->forceFill(['name' => 'Mia Manager'])->save();
        $this->actingAs($manager);

        Livewire::test(EditMenuItem::class, ['record' => $this->tea->getRouteKey()])
            ->fillForm(['price_cents' => '6.50'])
            ->call('save')
            ->assertHasNoFormErrors();

        $entry = AuditLog::query()->sole();

        expect($entry->only(['user_id', 'event', 'auditable_type', 'auditable_id', 'auditable_label', 'old_values', 'new_values']))->toBe([
            'user_id' => $manager->id,
            'event' => 'updated',
            'auditable_type' => MenuItem::class,
            'auditable_id' => $this->tea->id,
            'auditable_label' => 'Classic Milk Tea',
            'old_values' => ['price_cents' => 595],
            'new_values' => ['price_cents' => 650],
        ])->and($entry->created_at->equalTo(now()))->toBeTrue();

        // Done when: an admin sees who, when (Vancouver time), and from how much to how much.
        $this->actingAs(userWithRole('admin'));

        Livewire::test(ListAuditLogs::class)
            ->assertCanSeeTableRecords([$entry])
            ->assertSee('Nov 18, 2026 2:05 PM')
            ->assertSee('Mia Manager')
            ->assertSee('Classic Milk Tea')
            ->assertSee('Price: $5.95 → $6.50');
    });

    test('a sold-out toggle from the list is recorded', function () {
        $this->actingAs($manager = userWithRole('manager'));

        Livewire::test(ListMenuItems::class)->call('updateTableColumnState', 'is_available', strval($this->tea->id), false);

        expect(AuditLog::query()->sole()->only(['user_id', 'old_values', 'new_values']))->toBe([
            'user_id' => $manager->id,
            'old_values' => ['is_available' => true],
            'new_values' => ['is_available' => false],
        ]);
    });

    test('only the columns that changed are recorded, and a save with no change records nothing', function () {
        $this->tea->update(['price_cents' => 595]);
        $this->tea->touch();
        expect(AuditLog::count())->toBe(0);

        $this->tea->update(['name_en' => 'House Milk Tea', 'price_cents' => 625, 'sort_order' => $this->tea->sort_order]);

        expect(AuditLog::query()->sole()->only(['old_values', 'new_values']))->toBe([
            'old_values' => ['name_en' => 'Classic Milk Tea', 'price_cents' => 595],
            'new_values' => ['name_en' => 'House Milk Tea', 'price_cents' => 625],
        ]);
    });

    test('a modifier option change is recorded under its group', function () {
        $this->actingAs(userWithRole('manager'));

        Livewire::test(OptionsRelationManager::class, ['ownerRecord' => $this->toppings, 'pageClass' => EditModifierGroup::class])
            ->call('updateTableColumnState', 'is_available', strval($this->pearls->id), false);

        $entry = AuditLog::query()->sole();

        expect($entry->auditable_type)->toBe(ModifierOption::class)
            ->and($entry->auditable_label)->toBe('Toppings › Pearls')
            ->and($entry->new_values)->toBe(['is_available' => false]);
    });

    test('deleting a drink records everything it had', function () {
        $this->actingAs(userWithRole('admin'));

        Livewire::test(EditMenuItem::class, ['record' => $this->tea->getRouteKey()])->callAction('delete');

        $entry = AuditLog::query()->sole();

        expect($entry->event)->toBe('deleted')
            ->and($entry->new_values)->toBeNull()
            ->and($entry->old_values)->toMatchArray(['id' => $this->tea->id, 'name_en' => 'Classic Milk Tea', 'price_cents' => 595])
            ->and($entry->old_values)->not->toHaveKeys(['created_at', 'updated_at'])
            ->and(AuditChanges::lines($entry))->toContain('Price: $5.95');
    });

    test('a bulk delete records each drink', function () {
        $this->actingAs(userWithRole('admin'));
        $soda = MenuItem::factory()->create(['name_en' => 'Lemon Soda']);

        Livewire::test(ListMenuItems::class)
            ->selectTableRecords([$this->tea->getKey(), $soda->getKey()])
            ->callAction(TestAction::make('delete')->table()->bulk());

        expect(MenuItem::count())->toBe(0)
            ->and(AuditLog::query()->where('event', 'deleted')->orderBy('auditable_label')->pluck('auditable_label')->all())
            ->toBe(['Classic Milk Tea', 'Lemon Soda']);
    });

    test('deleting a modifier group records the deletion of each of its options', function () {
        $this->actingAs(userWithRole('admin'));
        ModifierOption::factory()->for($this->toppings)->create(['name_en' => 'Pudding']);

        Livewire::test(EditModifierGroup::class, ['record' => $this->toppings->getRouteKey()])->callAction('delete');

        expect(ModifierGroup::count())->toBe(0)
            ->and(ModifierOption::count())->toBe(0)
            ->and(AuditLog::query()->orderBy('auditable_label')->get(['event', 'auditable_type', 'auditable_label'])->toArray())->toBe([
                ['event' => 'deleted', 'auditable_type' => ModifierOption::class, 'auditable_label' => 'Toppings › Pearls'],
                ['event' => 'deleted', 'auditable_type' => ModifierOption::class, 'auditable_label' => 'Toppings › Pudding'],
            ]);
    });

    test('a group delete that throws keeps its options and their entries roll back too', function () {
        ModifierGroup::deleting(fn () => throw new RuntimeException('boom'));

        expect(fn () => $this->toppings->delete())->toThrow(RuntimeException::class);

        expect(ModifierOption::count())->toBe(1)
            ->and(AuditLog::count())->toBe(0);
    });

    test('a change made outside a request has no user', function () {
        $this->tea->update(['price_cents' => 650]);

        expect(AuditLog::query()->sole()->user_id)->toBeNull();
    });

    test('creating a drink or option is not recorded', function () {
        MenuItem::factory()->create();
        ModifierOption::factory()->for($this->toppings)->create();

        expect(AuditLog::count())->toBe(0);
    });
});

describe('append-only', function () {
    test('an entry cannot be changed or deleted', function () {
        $this->tea->update(['price_cents' => 650]);
        $entry = AuditLog::query()->sole();

        expect(fn () => $entry->update(['new_values' => ['price_cents' => 1]]))->toThrow(LogicException::class)
            ->and(fn () => $entry->delete())->toThrow(LogicException::class)
            ->and(AuditLog::query()->sole()->new_values)->toBe(['price_cents' => 650]);
    });

    test('the resource has only a list page and no create, edit, or delete', function () {
        $this->actingAs(userWithRole('admin'));
        $this->tea->update(['price_cents' => 650]);

        expect(array_keys(AuditLogResource::getPages()))->toBe(['index'])
            ->and(AuditLogResource::canCreate())->toBeFalse()
            ->and(AuditLogResource::canEdit(AuditLog::query()->sole()))->toBeFalse()
            ->and(AuditLogResource::canDelete(AuditLog::query()->sole()))->toBeFalse();
    });
});

describe('access', function () {
    test('admins can open the audit log', function () {
        $this->actingAs(userWithRole('admin'))->get(AuditLogResource::getUrl('index'))->assertOk();
    });

    test('managers, baristas, and customers are forbidden', function (string $role) {
        $this->actingAs(userWithRole($role))->get(AuditLogResource::getUrl('index'))->assertForbidden();
    })->with(['manager', 'barista', 'customer']);

    test('guests are sent to log in', function () {
        $this->get(AuditLogResource::getUrl('index'))->assertRedirect(route('login'));
    });

    // One request per test: Filament builds the navigation once per process.
    test('the navigation shows Audit log to admins', function () {
        $this->actingAs(userWithRole('admin'))->get('/admin')->assertOk()
            ->assertSee('href="'.AuditLogResource::getUrl('index').'"', escape: false);
    });

    test('the navigation does not show Audit log to managers', function () {
        $this->actingAs(userWithRole('manager'))->get('/admin')->assertOk()
            ->assertDontSee('href="'.AuditLogResource::getUrl('index').'"', escape: false);
    });

    test('a manager calling the list component directly is forbidden', function () {
        $this->actingAs(userWithRole('manager'));

        Livewire::test(ListAuditLogs::class)->assertForbidden();
    });
});
