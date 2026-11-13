<?php

use App\Filament\Resources\StoreHours\Pages\EditStoreHour;
use App\Filament\Resources\StoreHours\Pages\ListStoreHours;
use App\Filament\Resources\StoreHours\StoreHourResource;
use App\Models\StoreHour;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function wednesday(): StoreHour
{
    return StoreHour::where('day_of_week', 3)->firstOrFail();
}

describe('access', function () {
    test('guests are redirected to the login page', function () {
        $this->get(StoreHourResource::getUrl('index'))->assertRedirect(route('login'));
    });

    test('customers and baristas are forbidden', function (string $role) {
        $user = userWithRole($role);

        $this->actingAs($user)->get(StoreHourResource::getUrl('index'))->assertForbidden();
        $this->actingAs($user)->get(StoreHourResource::getUrl('edit', ['record' => wednesday()]))->assertForbidden();
    })->with(['customer', 'barista']);

    test('managers and admins can open the list and edit pages', function (string $role) {
        $this->actingAs(userWithRole($role));

        $this->get(StoreHourResource::getUrl('index'))
            ->assertOk()
            ->assertSeeInOrder(StoreHour::DAY_NAMES);
        $this->get(StoreHourResource::getUrl('edit', ['record' => wednesday()]))->assertOk();
    })->with(['manager', 'admin']);

    test('the policy allows only viewing and updating, and only for managers and admins', function (string $role, bool $canEdit) {
        $user = userWithRole($role);
        $hours = wednesday();

        expect($user->can('viewAny', StoreHour::class))->toBe($canEdit)
            ->and($user->can('update', $hours))->toBe($canEdit)
            ->and($user->can('create', StoreHour::class))->toBeFalse()
            ->and($user->can('delete', $hours))->toBeFalse()
            ->and($user->can('deleteAny', StoreHour::class))->toBeFalse();
    })->with([
        ['customer', false],
        ['barista', false],
        ['manager', true],
        ['admin', true],
    ]);
});

describe('no create or delete', function () {
    beforeEach(function () {
        $this->actingAs(userWithRole('admin'));
    });

    test('the resource has no create page', function () {
        expect(StoreHourResource::hasPage('create'))->toBeFalse();
    });

    test('the list page has no create or delete actions', function () {
        Livewire::test(ListStoreHours::class)
            ->assertCanSeeTableRecords(StoreHour::all())
            ->assertCountTableRecords(7)
            ->assertActionDoesNotExist('create')
            ->assertActionDoesNotExist(TestAction::make('delete')->table(wednesday()))
            ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());
    });

    test('the edit page has no delete action', function () {
        Livewire::test(EditStoreHour::class, ['record' => wednesday()->getRouteKey()])
            ->assertActionDoesNotExist('delete');
    });
});

describe('editing', function () {
    beforeEach(function () {
        $this->actingAs(userWithRole('manager'));
    });

    test('a manager can set opening hours, stored as wall-clock times', function () {
        Livewire::test(EditStoreHour::class, ['record' => wednesday()->getRouteKey()])
            ->fillForm([
                'is_closed' => false,
                'opens_at' => '10:30',
                'closes_at' => '22:00',
                'ordering_cutoff_minutes' => 20,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $hours = wednesday();

        // No timezone shift: 10:30 stays 10:30.
        expect($hours->opens_at)->toStartWith('10:30')
            ->and($hours->closes_at)->toStartWith('22:00')
            ->and($hours->is_closed)->toBeFalse()
            ->and($hours->ordering_cutoff_minutes)->toBe(20);
    });

    test('the edit form shows the stored times unchanged', function () {
        setStoreHours([3 => ['11:00:00', '21:00:00']]);

        Livewire::test(EditStoreHour::class, ['record' => wednesday()->getRouteKey()])
            // Shown without seconds, and not shifted by any timezone.
            ->assertSchemaStateSet([
                'opens_at' => '11:00',
                'closes_at' => '21:00',
            ]);
    });

    test('a closed day can be saved without times, and clears old times', function () {
        setStoreHours([3 => ['11:00:00', '21:00:00']]);

        Livewire::test(EditStoreHour::class, ['record' => wednesday()->getRouteKey()])
            ->fillForm(['is_closed' => true, 'opens_at' => null, 'closes_at' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(wednesday()->is_closed)->toBeTrue()
            ->and(wednesday()->opens_at)->toBeNull()
            ->and(wednesday()->closes_at)->toBeNull();
    });

    test('closing a day with times still filled in clears them', function () {
        setStoreHours([3 => ['11:00:00', '21:00:00']]);

        Livewire::test(EditStoreHour::class, ['record' => wednesday()->getRouteKey()])
            ->fillForm(['is_closed' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(wednesday()->opens_at)->toBeNull()
            ->and(wednesday()->closes_at)->toBeNull();
    });

    test('invalid hours are rejected', function (array $data, array $errors) {
        Livewire::test(EditStoreHour::class, ['record' => wednesday()->getRouteKey()])
            ->fillForm([
                'is_closed' => false,
                'opens_at' => '11:00',
                'closes_at' => '21:00',
                'ordering_cutoff_minutes' => 15,
                ...$data,
            ])
            ->call('save')
            ->assertHasFormErrors($errors);

        expect(wednesday()->is_closed)->toBeTrue();
    })->with([
        'open day missing both times' => [['opens_at' => null, 'closes_at' => null], ['opens_at' => 'required', 'closes_at' => 'required']],
        'open day missing closing time' => [['closes_at' => null], ['closes_at' => 'required']],
        'closing before opening' => [['opens_at' => '18:00', 'closes_at' => '09:00'], ['closes_at' => 'after']],
        'closing equal to opening' => [['opens_at' => '11:00', 'closes_at' => '11:00'], ['closes_at' => 'after']],
        'cutoff equal to opening minutes' => [['opens_at' => '11:00', 'closes_at' => '12:00', 'ordering_cutoff_minutes' => 60], ['ordering_cutoff_minutes']],
        'cutoff longer than opening minutes' => [['opens_at' => '11:00', 'closes_at' => '12:00', 'ordering_cutoff_minutes' => 90], ['ordering_cutoff_minutes']],
        'negative cutoff' => [['ordering_cutoff_minutes' => -5], ['ordering_cutoff_minutes' => 'min']],
        'missing cutoff' => [['ordering_cutoff_minutes' => null], ['ordering_cutoff_minutes' => 'required']],
    ]);

    test('a cutoff one minute shorter than the opening hours is accepted', function () {
        Livewire::test(EditStoreHour::class, ['record' => wednesday()->getRouteKey()])
            ->fillForm(['is_closed' => false, 'opens_at' => '11:00', 'closes_at' => '12:00', 'ordering_cutoff_minutes' => 59])
            ->call('save')
            ->assertHasNoFormErrors();
    });
});
