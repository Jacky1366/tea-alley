<?php

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\User;
use App\Support\PickupQr;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
 * T3-1 (#55): the pickup QR on a Ready order's page, and the staff-only confirmation page it
 * opens. Route access for every role is in RouteMatrixTest; replayed Livewire actions are in
 * LivewireActionAccessTest.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

/**
 * A Ready order for a named customer, with one line of 2 drinks and two options.
 */
function readyOrder(array $attributes = []): Order
{
    $customer = User::factory()->create(['name' => 'Mei Chen']);
    $order = Order::factory()->ready()->for($customer)->create($attributes);
    $item = OrderItem::factory()->for($order)->create(['name_en' => 'Classic Milk Tea', 'quantity' => 2]);
    OrderItemModifier::factory()->for($item)->create(['group_name_en' => 'Sugar', 'name_en' => '50% Sugar']);
    OrderItemModifier::factory()->for($item)->create(['group_name_en' => 'Toppings', 'name_en' => 'Pearls']);

    return $order;
}

describe('the QR on the order page', function () {
    test('shows only while the order is Ready', function (string $state, bool $shown) {
        $order = Order::factory()->{$state}()->create();

        $response = $this->actingAs($order->user)->get(route('orders.show', $order))->assertOk();

        $shown
            ? $response->assertSee('data-test="pickup-qr"', escape: false)->assertSee('Show this QR code at the counter')
            : $response->assertDontSee('data-test="pickup-qr"', escape: false);
    })->with([
        'paid' => ['paid', false],
        'preparing' => ['preparing', false],
        'ready' => ['ready', true],
        'picked up' => ['pickedUp', false],
        'cancelled' => ['cancelled', false],
    ]);

    test('is an SVG image (no raw markup in the page) of the order\'s signed pickup link', function () {
        $order = readyOrder();
        $html = $this->actingAs($order->user)->get(route('orders.show', $order))->getContent();

        preg_match('/src="data:image\/svg\+xml;base64,([^"]+)"[^>]*data-test="pickup-qr"/', $html, $match);

        expect($match)->not->toBeEmpty()
            ->and(base64_decode($match[1]))->toContain('<svg')
            ->and($html)->toMatch('/data-test="pickup-qr-number"[^>]*>\s*'.$order->order_number.'/');
    });

    test('the link is signed relatively, for this order, and the same on every render', function () {
        $order = readyOrder();
        $url = PickupQr::url($order);
        parse_str(strval(parse_url($url, PHP_URL_QUERY)), $query);

        expect(URL::hasValidRelativeSignature(Request::create($url)))->toBeTrue()
            ->and(parse_url($url, PHP_URL_PATH))->toBe('/barista/pickup/'.$order->order_number)
            ->and(PickupQr::url($order))->toBe($url)
            ->and($query)->toHaveKey('signature')
            ->and($query)->not->toHaveKey('expires');
    });

    test('appears when the barista bumps, without a reload, and goes away on Recall', function () {
        $order = Order::factory()->preparing()->create();
        $page = Livewire::actingAs($order->user)->test('pages::orders.show', ['order' => $order]);
        $event = 'echo-private:orders.'.$order->id.',OrderStatusChanged';

        expect($page->html())->not->toContain('data-test="pickup-qr"');

        Order::query()->findOrFail($order->id)->transitionTo(OrderStatus::Ready);
        $page->dispatch($event, ['order_id' => $order->id, 'status' => 'ready']);
        expect($page->html())->toContain('data-test="pickup-qr"');

        Order::query()->findOrFail($order->id)->transitionTo(OrderStatus::Preparing);
        $page->dispatch($event, ['order_id' => $order->id, 'status' => 'preparing']);
        expect($page->html())->not->toContain('data-test="pickup-qr"');
    });
});

describe('the confirmation page', function () {
    test('opening it shows the order and changes nothing', function () {
        Event::fake([OrderStatusChanged::class]);
        $order = readyOrder();

        $this->actingAs(userWithRole('barista'))->get(PickupQr::path($order))
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('Mei Chen')
            ->assertSee('2 drinks')
            ->assertSee('Classic Milk Tea × 2')
            ->assertSee('Sugar: 50% Sugar')
            ->assertSee('data-test="confirm-pickup"', escape: false);

        expect($order->fresh()->status)->toBe(OrderStatus::Ready);
        Event::assertNotDispatched(OrderStatusChanged::class);
    });

    test('a signed link works on another host too (relative signature)', function () {
        $order = readyOrder();

        $this->actingAs(userWithRole('barista'))
            ->get('https://some-tunnel.example'.PickupQr::path($order))
            ->assertOk();
    });

    test('a signature from another order doesn\'t open this one', function () {
        $order = readyOrder();
        $other = readyOrder();
        $signature = parse_url(PickupQr::path($other), PHP_URL_QUERY);

        $this->actingAs(userWithRole('barista'))
            ->get('/barista/pickup/'.$order->order_number.'?'.$signature)
            ->assertForbidden();
    });

    test('Confirm pickup moves it to Picked up and tells the customer\'s page and the board', function (string $role) {
        Event::fake([OrderStatusChanged::class]);
        $order = readyOrder();

        Livewire::actingAs(userWithRole($role))->test('pages::barista.pickup', ['order' => $order])
            ->call('confirm')
            ->assertSee('data-test="pickup-done"', escape: false)
            ->assertDontSee('data-test="confirm-pickup"', escape: false);

        expect($order->fresh()->status)->toBe(OrderStatus::PickedUp)
            ->and($order->fresh()->picked_up_at)->not->toBeNull();
        Event::assertDispatched(OrderStatusChanged::class, fn (OrderStatusChanged $e) => $e->orderId === $order->id && $e->status === OrderStatus::PickedUp);
    })->with(['barista', 'manager', 'admin']);

    test('an order that isn\'t Ready says why, has no button, and can\'t be picked up', function (string $state, string $message) {
        // The factory's default state is "waiting for payment".
        $order = $state === 'pendingPayment' ? Order::factory()->create() : Order::factory()->{$state}()->create();

        Livewire::actingAs(userWithRole('barista'))->test('pages::barista.pickup', ['order' => $order])
            ->assertSee($message)
            ->assertDontSee('data-test="confirm-pickup"', escape: false)
            ->call('confirm')
            ->assertSee($message);

        expect($order->fresh()->status)->toBe($order->status);
    })->with([
        'paid' => ['paid', "isn't ready yet"],
        'preparing' => ['preparing', "isn't ready yet"],
        'picked up' => ['pickedUp', 'already picked up'],
        'cancelled' => ['cancelled', 'was cancelled'],
        'waiting for payment' => ['pendingPayment', "hasn't been paid"],
    ]);

    test('two baristas confirming the same order: one succeeds, the other is told it\'s already picked up', function () {
        Event::fake([OrderStatusChanged::class]);
        $order = readyOrder();
        $first = Livewire::actingAs(userWithRole('barista'))->test('pages::barista.pickup', ['order' => $order]);
        $second = Livewire::actingAs(userWithRole('barista'))->test('pages::barista.pickup', ['order' => $order]);

        $first->call('confirm')->assertSee('data-test="pickup-done"', escape: false);
        $second->call('confirm')
            ->assertSee('data-test="pickup-already"', escape: false)
            ->assertDontSee('data-test="pickup-done"', escape: false);

        expect($order->fresh()->status)->toBe(OrderStatus::PickedUp);
        Event::assertDispatchedTimes(OrderStatusChanged::class, 1);
    });

    test('recalled while the page was open: confirming says it isn\'t ready, and it stays recalled', function () {
        $order = readyOrder();
        $page = Livewire::actingAs(userWithRole('barista'))->test('pages::barista.pickup', ['order' => $order]);

        Order::query()->findOrFail($order->id)->transitionTo(OrderStatus::Preparing);
        $page->call('confirm')->assertSee('isn&#039;t ready yet', escape: false);

        expect($order->fresh()->status)->toBe(OrderStatus::Preparing);
    });
});

describe('logging in from a pickup link', function () {
    test('a guest is sent to log in, then lands back on the same signed link, which still works', function () {
        $order = readyOrder();
        $link = PickupQr::url($order);
        $barista = userWithRole('barista');

        $this->get($link)->assertRedirect(route('login'));

        $response = $this->post(route('login.store'), ['email' => $barista->email, 'password' => 'password']);

        // The intended URL keeps the query string, so the signature survives.
        $response->assertRedirect($link);
        expect(URL::hasValidRelativeSignature(Request::create($response->headers->get('Location'))))->toBeTrue();

        $this->get($response->headers->get('Location'))->assertOk()->assertSee($order->order_number);
    });
});
