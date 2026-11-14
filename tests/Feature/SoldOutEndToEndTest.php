<?php

use App\Enums\OrderStatus;
use App\Filament\Resources\MenuItems\Pages\ListMenuItems;
use App\Filament\Resources\ModifierGroups\Pages\EditModifierGroup;
use App\Filament\Resources\ModifierGroups\RelationManagers\OptionsRelationManager;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\User;
use App\Services\Cart;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

/*
 * The Stage 1 demo checkpoint: a manager 86es an option and a drink from the Filament
 * lists, and the customer sees it on the next request everywhere (builder, cart,
 * checkout, menu), then orders normally once both are restored.
 *
 * Fixture: "Demo Milk Tea" ($6.00) with Size (Medium default +0) and Toppings (Pearls +75).
 * Ordering is open 11:00-22:00; the clock is Wed Nov 18, 2026 12:00 in Vancouver.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    setStoreHours(array_fill(0, 7, ['11:00:00', '22:00:00']));
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'America/Vancouver'));

    $this->tea = MenuItem::factory()->create(['name_en' => 'Demo Milk Tea', 'price_cents' => 600]);
    $this->size = ModifierGroup::factory()->create(['name_en' => 'Size', 'is_required' => true, 'max_select' => 1]);
    $this->toppings = ModifierGroup::factory()->create(['name_en' => 'Toppings', 'max_select' => 3]);
    $this->tea->modifierGroups()->attach([$this->size->id => ['sort_order' => 1], $this->toppings->id => ['sort_order' => 2]]);
    $this->medium = ModifierOption::factory()->for($this->size)->create(['name_en' => 'Medium', 'price_delta_cents' => 0, 'is_default' => true, 'sort_order' => 1]);
    $this->pearls = ModifierOption::factory()->for($this->toppings)->create(['name_en' => 'Pearls', 'price_delta_cents' => 75, 'sort_order' => 1]);

    $this->customer = userWithRole('customer');
    $this->manager = userWithRole('manager');
});

/**
 * Flip is_available through the Filament list, as the manager.
 */
function eightySix(User $manager, MenuItem|ModifierOption $record, bool $available): void
{
    test()->actingAs($manager);

    $table = $record instanceof MenuItem
        ? Livewire::test(ListMenuItems::class)
        : Livewire::test(OptionsRelationManager::class, ['ownerRecord' => $record->modifierGroup, 'pageClass' => EditModifierGroup::class]);

    $table->call('updateTableColumnState', 'is_available', strval($record->id), $available);

    expect($record->fresh()->is_available)->toBe($available);
}

/**
 * Whether the builder's input for this option is disabled.
 */
function optionDisabled(string $html, ModifierOption $option): bool
{
    expect(preg_match('/<input\b[^>]*data-test="option-'.$option->id.'"[^>]*>/', $html, $match))->toBe(1);

    return (bool) preg_match('/\sdisabled(\s|=|>)/', $match[0]);
}

test('the Stage 1 sold-out demo works end to end', function () {
    // 1. The customer adds a milk tea with Pearls, and opens checkout.
    $this->actingAs($this->customer);

    Livewire::test('pages::drink', ['item' => $this->tea])
        ->set('selected.'.$this->size->id, strval($this->medium->id))
        ->set('selected.'.$this->toppings->id, [strval($this->pearls->id)])
        ->call('addToCart')
        ->assertHasNoErrors();

    expect(app(Cart::class)->count())->toBe(1);
    expect(optionDisabled($this->get(route('menu.show', $this->tea))->getContent(), $this->pearls))->toBeFalse();

    $openCheckout = Livewire::test('pages::checkout')->assertNoRedirect();

    // 2. The manager 86es Pearls from the modifier group's option list.
    eightySix($this->manager, $this->pearls, false);

    // 3. The customer's drink builder: Pearls is disabled.
    $this->actingAs($this->customer);
    expect(optionDisabled($this->get(route('menu.show', $this->tea))->getContent(), $this->pearls))->toBeTrue();

    // 4. The customer's cart: the line is invalid, with the reason.
    $this->get(route('cart'))
        ->assertSee('data-test="invalid-line"', escape: false)
        ->assertSee('Pearls is sold out.');

    // 5. Checkout is blocked, both for a checkout page already open and a new one; no order.
    $openCheckout->call('placeOrder')->assertHasErrors('cart');
    Livewire::test('pages::checkout')->assertRedirect(route('cart'));
    expect(Order::count())->toBe(0);

    // 6. The manager 86es the whole drink: it disappears from the menu.
    $this->get(route('home'))->assertSee('Demo Milk Tea');
    eightySix($this->manager, $this->tea, false);

    $this->actingAs($this->customer)->get(route('home'))->assertDontSee('Demo Milk Tea');

    // 7. The manager restores both; the customer can order, and the receipt is right.
    eightySix($this->manager, $this->pearls, true);
    eightySix($this->manager, $this->tea, true);

    $this->actingAs($this->customer);
    $this->get(route('home'))->assertSee('Demo Milk Tea');
    $this->get(route('cart'))->assertDontSee('data-test="invalid-line"', escape: false);

    $page = Livewire::test('pages::checkout')->call('placeOrder')->assertHasNoErrors();
    $order = Order::sole();
    $page->assertRedirect(route('orders.show', $order));

    // $6.00 + Pearls $0.75 = $6.75; GST 5% = 0.3375, rounded to 0.34; total $7.09.
    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->user_id)->toBe($this->customer->id)
        ->and($order->total_cents)->toBe(709);

    $this->get(route('orders.show', $order))
        ->assertOk()
        ->assertSee('Demo Milk Tea × 1')
        ->assertSee('Toppings: Pearls')
        ->assertSeeInOrder(['data-test="subtotal"', '$6.75', 'data-test="gst"', '$0.34', 'data-test="total"', '$7.09'], escape: false);
});
