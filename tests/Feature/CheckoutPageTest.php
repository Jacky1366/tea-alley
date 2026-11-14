<?php

use App\Enums\OrderStatus;
use App\Enums\PickupType;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\User;
use App\Services\Cart;
use App\Services\ModifierValidator;
use App\Services\PricingService;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Fixture: "Checkout Tea" ($6.00, prep 5) with a required Size group (Medium +0, Large +50),
 * and "Checkout Soda" ($5.00, carbonated, prep 2). Ordering is open 11:00-22:00 (cutoff 15)
 * every day; the clock is Wed Nov 18, 2026 12:00 in Vancouver.
 */
beforeEach(function () {
    setStoreHours(array_fill(0, 7, ['11:00:00', '22:00:00']));
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'America/Vancouver'));

    $this->tea = MenuItem::factory()->create(['name_en' => 'Checkout Tea', 'price_cents' => 600, 'prep_minutes' => 5]);
    $size = ModifierGroup::factory()->create(['name_en' => 'Size', 'is_required' => true, 'max_select' => 1]);
    $this->tea->modifierGroups()->attach($size->id, ['sort_order' => 1]);
    $this->medium = ModifierOption::factory()->for($size)->create(['name_en' => 'Medium', 'price_delta_cents' => 0, 'sort_order' => 1]);
    $this->large = ModifierOption::factory()->for($size)->create(['name_en' => 'Large', 'price_delta_cents' => 50, 'sort_order' => 2]);

    $this->soda = MenuItem::factory()->carbonated()->create(['name_en' => 'Checkout Soda', 'price_cents' => 500, 'prep_minutes' => 2]);

    $this->user = User::factory()->create();
    $this->cart = app(Cart::class);
});

/**
 * Large tea x2 and a soda: 2 x 6.50 + 5.00 = 18.00, GST 0.90, PST 0.35, total 19.25.
 */
function fillCheckoutCart(object $t): void
{
    $validator = app(ModifierValidator::class);

    $t->cart->add($validator->validate(MenuItem::findOrFail($t->tea->id), [$t->large->id], 2));
    $t->cart->add($validator->validate(MenuItem::findOrFail($t->soda->id), [], 1));
}

function checkoutPage(User $user): Testable
{
    return Livewire::actingAs($user)->test('pages::checkout');
}

function vancouverAt(string $time): CarbonImmutable
{
    return CarbonImmutable::parse('2026-11-18 '.$time, 'America/Vancouver');
}

function slotValue(CarbonImmutable $time): string
{
    return $time->utc()->format('Y-m-d\TH:i:s\Z');
}

describe('access', function () {
    test('the route requires a verified login', function () {
        expect(app('router')->getRoutes()->getByName('checkout')->gatherMiddleware())
            ->toContain('auth', 'verified');
    });

    test('guests are sent to log in', function () {
        $this->get(route('checkout'))->assertRedirect(route('login'));
    });

    test('unverified users are sent to verify their email', function () {
        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('checkout'))
            ->assertRedirect(route('verification.notice'));
    });

    test('a guest who logs in comes back to checkout with the cart intact', function () {
        fillCheckoutCart($this);

        $this->get(route('checkout'))->assertRedirect(route('login'));

        $this->post(route('login.store'), ['email' => $this->user->email, 'password' => 'password'])
            ->assertRedirect(route('checkout'));

        $this->get(route('checkout'))
            ->assertOk()
            ->assertSee('Checkout Tea')
            ->assertSee('Checkout Soda');

        expect($this->cart->count())->toBe(3);
    });

    test('an empty cart goes back to the cart page', function () {
        checkoutPage($this->user)->assertRedirect(route('cart'));
    });

    test('a cart with invalid lines goes back to the cart page with a message', function () {
        fillCheckoutCart($this);
        $this->soda->update(['is_available' => false]);

        checkoutPage($this->user)->assertRedirect(route('cart'));

        $this->actingAs($this->user)->get(route('cart'))->assertSee('data-test="checkout-status"', escape: false);
    });

    test('the cart page links to checkout', function () {
        fillCheckoutCart($this);

        $this->get(route('cart'))->assertSee('href="'.route('checkout').'"', escape: false);
    });
});

describe('the page', function () {
    test('shows each line and the totals from PricingService', function () {
        fillCheckoutCart($this);
        $breakdown = app(PricingService::class)->price($this->cart->contents()->valid);

        expect($breakdown->total_cents)->toBe(1925);

        checkoutPage($this->user)
            ->assertSee('Checkout Tea × 2')
            ->assertSee('Size: Large')
            ->assertSeeHtmlInOrder([
                'data-test="subtotal"', '$18.00',
                'data-test="gst"', '$0.90',
                'data-test="pst"', '$0.35',
                'data-test="total"', '$19.25',
            ]);
    });

    test('shows the ASAP estimate from the longest prep time', function () {
        fillCheckoutCart($this);

        checkoutPage($this->user)->assertSee('Ready around 12:05 PM');
    });

    test('lists today\'s slots in Vancouver time', function () {
        fillCheckoutCart($this);

        checkoutPage($this->user)
            ->set('pickupType', 'scheduled')
            ->assertSeeHtml('data-test="pickup-slot"')
            ->assertSeeHtml('value="'.slotValue(vancouverAt('12:15')).'"')
            ->assertSeeHtml('value="'.slotValue(vancouverAt('21:45')).'"')
            ->assertDontSeeHtml('value="'.slotValue(vancouverAt('12:00')).'"')
            ->assertDontSeeHtml('value="'.slotValue(vancouverAt('22:00')).'"')
            ->assertSee('12:15 PM')
            ->assertSee('9:45 PM');
    });

    test('hides scheduling when no slot is left', function () {
        fillCheckoutCart($this);
        // 21:40:01 + 5 minutes prep = 21:45:01, past the last slot at 21:45.
        $this->travelTo(vancouverAt('21:40:01'));

        checkoutPage($this->user)
            ->assertSeeHtml('data-test="pickup-asap"')
            ->assertDontSeeHtml('data-test="pickup-scheduled"');
    });

    test('ordering closed shows the banner and disables the button', function () {
        fillCheckoutCart($this);
        $this->travelTo(vancouverAt('22:30'));

        $html = checkoutPage($this->user)->assertSeeHtml('data-test="ordering-closed"')->html();

        expect(preg_match('/<button\b[^>]*data-test="place-order"[^>]*>/', $html, $match))->toBe(1)
            ->and($match[0])->toMatch('/\sdisabled(\s|=|>)/');
    });

    test('the button is disabled while the order is being placed', function () {
        fillCheckoutCart($this);

        checkoutPage($this->user)->assertSeeHtml('wire:loading.attr="disabled"');
    });
});

describe('placing the order', function () {
    test('places, pays, empties the cart, and goes to the receipt', function () {
        fillCheckoutCart($this);

        $page = checkoutPage($this->user)->call('placeOrder');

        $order = Order::sole();

        $page->assertHasNoErrors()
            ->assertDispatched('cart-updated')
            ->assertRedirect(url('/orders/'.$order->order_number));

        expect($order->user_id)->toBe($this->user->id)
            ->and($order->status)->toBe(OrderStatus::Paid)
            ->and($order->total_cents)->toBe(1925)
            ->and($order->pickup_type)->toBe(PickupType::Asap)
            ->and($this->cart->count())->toBe(0);
    });

    test('a scheduled slot is placed', function () {
        fillCheckoutCart($this);

        checkoutPage($this->user)
            ->set('pickupType', 'scheduled')
            ->set('pickupSlot', slotValue(vancouverAt('15:30')))
            ->call('placeOrder')
            ->assertHasNoErrors();

        expect(Order::sole()->pickup_at->equalTo(vancouverAt('15:30')))->toBeTrue();
    });

    test('submitting twice gives one order', function () {
        fillCheckoutCart($this);

        $page = checkoutPage($this->user);
        $page->call('placeOrder');
        $order = Order::sole();

        // The second submit has an empty cart, but finds the order by its key.
        $page->call('placeOrder')
            ->assertHasNoErrors()
            ->assertRedirect(url('/orders/'.$order->order_number));

        expect(Order::count())->toBe(1);
    });

    test('each page load gets its own key', function () {
        fillCheckoutCart($this);

        expect(checkoutPage($this->user)->get('idempotencyKey'))
            ->toMatch('/^[0-9a-f-]{36}$/')
            ->not->toBe(checkoutPage($this->user)->get('idempotencyKey'));
    });

    test('ordering closed is blocked on the server even if the action is called', function () {
        fillCheckoutCart($this);
        $page = checkoutPage($this->user);

        $this->travelTo(vancouverAt('21:50'));

        $page->call('placeOrder')->assertHasErrors('ordering');

        expect(Order::count())->toBe(0)
            ->and($this->cart->count())->toBe(3);
    });

    test('a drink sold out while on the page blocks the order', function () {
        fillCheckoutCart($this);
        $page = checkoutPage($this->user);

        $this->large->update(['is_available' => false]);

        $page->call('placeOrder')->assertHasErrors('cart');

        expect(Order::count())->toBe(0);
    });
});

describe('tampering', function () {
    test('the only client-writable properties are the pickup choice', function () {
        fillCheckoutCart($this);

        expect(array_keys(checkoutPage($this->user)->instance()->all()))
            ->toEqualCanonicalizing(['idempotencyKey', 'pickupType', 'pickupSlot']);
    });

    test('the idempotency key is locked', function () {
        fillCheckoutCart($this);

        expect(fn () => checkoutPage($this->user)->set('idempotencyKey', 'someone-elses-key'))
            ->toThrow(CannotUpdateLockedPropertyException::class);
    });

    test('an unknown pickup type is rejected', function () {
        fillCheckoutCart($this);

        checkoutPage($this->user)->set('pickupType', 'free')->call('placeOrder')->assertHasErrors('pickup');

        expect(Order::count())->toBe(0);
    });

    test('a bad pickup slot is rejected', function (string $slot) {
        fillCheckoutCart($this);

        checkoutPage($this->user)
            ->set('pickupType', 'scheduled')
            ->set('pickupSlot', $slot)
            ->call('placeOrder')
            ->assertHasErrors('pickup');

        expect(Order::count())->toBe(0);
    })->with([
        'empty' => '',
        'garbage' => 'tomorrow at noon',
        'impossible date' => '2026-02-31T20:00:00Z',
        'not a slot' => fn () => slotValue(vancouverAt('15:10')),
        'too soon' => fn () => slotValue(vancouverAt('12:00')),
        'after ordering ends' => fn () => slotValue(vancouverAt('22:00')),
        'tomorrow' => fn () => slotValue(vancouverAt('15:00')->addDay()),
    ]);
});
