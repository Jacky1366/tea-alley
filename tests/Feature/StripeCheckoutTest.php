<?php

use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Exceptions\PaymentUnavailable;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Cart;
use App\Services\ModifierValidator;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\StripePaymentGateway;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;
use Tests\Support\FakeStripeHttpClient;
use Tests\Support\RefusingStripeHttpClient;

/*
 * The clock is Wed Nov 18, 2026 12:00 in Vancouver; APP_URL is https://tea-alley.example.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'America/Vancouver'));
    config(['app.url' => 'https://tea-alley.example']);
    $this->stripe = useStripe();
});

/**
 * A pending order for a customer with $locale: Classic Milk Tea x2, total $13.12.
 *
 * @param  array<string, mixed>  $attributes
 */
function pendingStripeOrder(?string $locale = null, array $attributes = []): Order
{
    $customer = User::factory()->create(['locale' => $locale]);
    $order = Order::factory()->for($customer)->create(['total_cents' => 1312, ...$attributes]);
    OrderItem::factory()->for($order)->create(['name_en' => 'Classic Milk Tea', 'name_zh' => '經典奶茶', 'quantity' => 2]);

    return $order;
}

/**
 * The params of the n-th recorded Stripe request.
 *
 * @return array<mixed>
 */
function stripeParams(FakeStripeHttpClient $stripe, int $n = 0): array
{
    return $stripe->requests[$n]['params'];
}

describe('the Checkout Session', function () {
    test('is one line item for the order total in CAD, tied to the order, expiring in 31 minutes', function () {
        $order = pendingStripeOrder();
        $session = stripeSession();
        $this->stripe->respond(200, $session);

        $url = app(PaymentGateway::class)->startPayment($order);

        $receipt = 'https://tea-alley.example/orders/'.$order->order_number;
        expect($this->stripe->calls())->toBe(['POST /v1/checkout/sessions'])
            ->and(stripeParams($this->stripe))->toMatchArray([
                'mode' => 'payment',
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => 'cad',
                        'unit_amount' => 1312,
                        'product_data' => [
                            'name' => "Tea Alley order {$order->order_number}",
                            'description' => '2 × Classic Milk Tea',
                        ],
                    ],
                ]],
                'metadata' => ['order_id' => (string) $order->id],
                'client_reference_id' => (string) $order->id,
                'expires_at' => now()->addMinutes(31)->getTimestamp(),
                'success_url' => $receipt.'?checkout=success',
                'cancel_url' => $receipt.'?checkout=cancelled',
                'locale' => 'en',
            ])
            ->and($url)->toBe($session['url'])
            // Stripe rejects it since API version 2026-10-28: methods are set in the Dashboard.
            ->and(stripeParams($this->stripe))->not->toHaveKey('payment_method_types');

        $order->refresh();
        expect($order->stripe_session_id)->toBe($session['id'])
            ->and($order->payment_expires_at->getTimestamp())->toBe($session['expires_at'])
            ->and($order->status)->toBe(OrderStatus::PendingPayment);  // only the webhook marks it paid
    });

    test('the amount is the stored total, never recalculated from the menu', function () {
        $order = pendingStripeOrder(attributes: ['total_cents' => 777]);
        MenuItem::query()->update(['price_cents' => 99999]);
        $this->stripe->respond(200, stripeSession());

        app(PaymentGateway::class)->startPayment($order);

        expect(stripeParams($this->stripe)['line_items'][0]['price_data']['unit_amount'])->toBe(777);
    });

    test('is in Traditional Chinese for a zh_TW customer', function () {
        $order = pendingStripeOrder('zh_TW');
        $this->stripe->respond(200, stripeSession());

        app(PaymentGateway::class)->startPayment($order);

        $params = stripeParams($this->stripe);
        expect($params['locale'])->toBe('zh-TW')
            ->and($params['line_items'][0]['price_data']['product_data'])
            ->toBe(['name' => "Tea Alley 茶巷訂單 {$order->order_number}", 'description' => '2 × 經典奶茶'])
            ->and(app()->getLocale())->toBe('en');
    });

    test('is never created for an order that is already paid', function (string $state) {
        $order = Order::factory()->{$state}()->create();

        expect(app(PaymentGateway::class)->startPayment($order))->toBe(route('orders.show', $order))
            ->and($this->stripe->requests)->toBe([]);
    })->with(['paid', 'preparing', 'ready', 'pickedUp']);

    test('is never created for a cancelled order', function () {
        $order = Order::factory()->cancelled()->create();

        expect(fn () => app(PaymentGateway::class)->startPayment($order))->toThrow(InvalidOrderTransition::class);
        expect($this->stripe->requests)->toBe([]);
    });

    test('a Stripe error leaves the order pending and unchanged', function () {
        $order = pendingStripeOrder();
        $this->stripe->respond(400, ['error' => ['type' => 'invalid_request_error', 'message' => 'Nope']]);

        expect(fn () => app(PaymentGateway::class)->startPayment($order))->toThrow(PaymentUnavailable::class);

        expect($order->fresh()->stripe_session_id)->toBeNull()
            ->and($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    });
});

describe('paying again', function () {
    test('reuses the current Session while Stripe still has it open', function () {
        $open = stripeSession();
        $order = pendingStripeOrder(attributes: ['stripe_session_id' => $open['id']]);
        $this->stripe->respond(200, $open);

        expect(app(PaymentGateway::class)->startPayment($order))->toBe($open['url'])
            ->and($this->stripe->calls())->toBe(['GET /v1/checkout/sessions/'.$open['id']]);
    });

    test('creates a new Session for the same order once the old one has expired', function () {
        $old = stripeSession(['status' => 'expired', 'url' => null]);
        $new = stripeSession();
        $order = pendingStripeOrder(attributes: ['stripe_session_id' => $old['id']]);
        $this->stripe->respond(200, $old)->respond(200, $new);

        expect(app(PaymentGateway::class)->startPayment($order))->toBe($new['url'])
            ->and($this->stripe->calls())->toBe(['GET /v1/checkout/sessions/'.$old['id'], 'POST /v1/checkout/sessions'])
            ->and($order->fresh()->stripe_session_id)->toBe($new['id'])
            ->and(Order::count())->toBe(1);
    });

    test('a Session that is already paid goes to the receipt to wait for the webhook', function () {
        $paid = stripeSession(['status' => 'complete', 'payment_status' => 'paid', 'url' => null]);
        $order = pendingStripeOrder(attributes: ['stripe_session_id' => $paid['id']]);
        $this->stripe->respond(200, $paid);

        expect(app(PaymentGateway::class)->startPayment($order))->toBe($order->receiptUrl(['checkout' => 'success']))
            ->and($this->stripe->calls())->toBe(['GET /v1/checkout/sessions/'.$paid['id']]);
    });

    test('a double-click creates one Session: the second request reuses it', function () {
        $order = pendingStripeOrder();
        $session = stripeSession();
        $this->stripe->respond(200, $session)->respond(200, $session);

        $first = app(PaymentGateway::class)->startPayment(Order::findOrFail($order->id));
        $second = app(PaymentGateway::class)->startPayment(Order::findOrFail($order->id));

        expect([$first, $second])->toBe([$session['url'], $session['url']])
            ->and($this->stripe->calls())->toBe(['POST /v1/checkout/sessions', 'GET /v1/checkout/sessions/'.$session['id']]);
    });

    test('a request that waited for the lock sees the order paid meanwhile, and calls Stripe not at all', function () {
        $order = pendingStripeOrder();
        $stale = Order::findOrFail($order->id);
        $order->transitionTo(OrderStatus::Paid);

        expect(app(PaymentGateway::class)->startPayment($stale))->toBe(route('orders.show', $order))
            ->and($this->stripe->requests)->toBe([]);
    });
});

describe('checkout with Stripe', function () {
    beforeEach(function () {
        setStoreHours(array_fill(0, 7, ['11:00:00', '22:00:00']));
        $this->customer = User::factory()->create();
        $tea = MenuItem::factory()->create(['price_cents' => 600]);
        app(Cart::class)->add(app(ModifierValidator::class)->validate($tea, [], 2));
    });

    test('places a pending order, empties the cart, and sends the customer to Stripe', function () {
        $session = stripeSession();
        $this->stripe->respond(200, $session);

        Livewire::actingAs($this->customer)->test('pages::checkout')
            ->call('placeOrder')
            ->assertRedirect($session['url']);

        $order = Order::sole();
        expect($order->status)->toBe(OrderStatus::PendingPayment)
            ->and($order->stripe_session_id)->toBe($session['id'])
            ->and(app(Cart::class)->count())->toBe(0);
    });

    test('a Stripe error is a friendly message on the receipt, not a 500', function () {
        $this->stripe->respond(400, ['error' => ['type' => 'api_error', 'message' => 'Down']]);

        Livewire::actingAs($this->customer)->test('pages::checkout')
            ->call('placeOrder')
            ->assertRedirect(route('orders.show', Order::sole()));

        expect(Order::sole()->status)->toBe(OrderStatus::PendingPayment)
            ->and(session('payment-status'))->toBe("We couldn't start the payment. Please try again.")
            ->and(app(Cart::class)->count())->toBe(0);
    });
});

describe('the receipt while payment is pending', function () {
    test('back from paying: "confirming", and no retry button', function () {
        $order = pendingStripeOrder();

        $this->actingAs($order->user)->get(route('orders.show', $order).'?checkout=success')
            ->assertOk()
            ->assertSee('Confirming your payment…')
            ->assertDontSee('data-test="retry-payment"', escape: false);
    });

    test('back from Stripe\'s back button, or any other visit: "not completed" and a retry button', function (string $query) {
        $order = pendingStripeOrder();

        $this->actingAs($order->user)->get(route('orders.show', $order).$query)
            ->assertOk()
            ->assertSee('Payment not completed.')
            ->assertSee('data-test="retry-payment"', escape: false);
    })->with(['cancelled' => '?checkout=cancelled', 'none' => '', 'anything else' => '?checkout=<script>']);

    test('a cancelled order has no retry button', function () {
        $order = pendingStripeOrder(attributes: ['status' => OrderStatus::Cancelled]);

        $this->actingAs($order->user)->get(route('orders.show', $order))
            ->assertOk()
            ->assertDontSee('data-test="retry-payment"', escape: false);
    });

    test('retrying sends the customer to Stripe for the same order', function () {
        $order = pendingStripeOrder();
        $session = stripeSession();
        $this->stripe->respond(200, $session);

        Livewire::actingAs($order->user)->test('pages::orders.show', ['order' => $order])
            ->call('retryPayment')
            ->assertRedirect($session['url']);

        expect(Order::count())->toBe(1)
            ->and($order->fresh()->stripe_session_id)->toBe($session['id']);
    });

    test('someone else cannot retry', function () {
        $order = pendingStripeOrder();
        $page = Livewire::actingAs($order->user)->test('pages::orders.show', ['order' => $order]);
        $this->actingAs(User::factory()->create());

        $page->call('retryPayment')->assertNotFound();

        expect($this->stripe->requests)->toBe([]);
    });

    test('an order cancelled while the page was open cannot be retried: a message, no Stripe call', function () {
        $order = pendingStripeOrder();
        $page = Livewire::actingAs($order->user)->test('pages::orders.show', ['order' => $order]);
        $order->transitionTo(OrderStatus::Cancelled);

        $page->call('retryPayment')
            ->assertOk()
            ->assertNoRedirect()
            ->assertSee('This order can no longer be paid.');

        expect($this->stripe->requests)->toBe([]);
    });

    test('a Stripe error on retry is a message, not a 500', function () {
        $order = pendingStripeOrder();
        $this->stripe->respond(400, ['error' => ['type' => 'api_error', 'message' => 'Down']]);

        Livewire::actingAs($order->user)->test('pages::orders.show', ['order' => $order])
            ->call('retryPayment')
            ->assertOk()
            ->assertNoRedirect()
            ->assertSee("We couldn't start the payment. Please try again.");
    });
});

describe('payment messages on the receipt', function () {
    test("a failed retry's message survives the page's polling refreshes", function () {
        $order = pendingStripeOrder();
        $this->stripe->respond(400, ['error' => ['type' => 'api_error', 'message' => 'Down']]);

        $page = Livewire::actingAs($order->user)->test('pages::orders.show', ['order' => $order])
            ->call('retryPayment');

        foreach (range(1, 3) as $poll) {
            $page->call('$refresh')->assertSee("We couldn't start the payment. Please try again.");
        }
    });

    test("checkout's message is shown on the receipt and kept across refreshes", function () {
        $order = pendingStripeOrder();
        session()->flash('payment-status', "We couldn't start the payment. Please try again.");

        $page = Livewire::actingAs($order->user)->test('pages::orders.show', ['order' => $order])
            ->assertSee("We couldn't start the payment. Please try again.");

        session()->forget('payment-status');
        $page->call('$refresh')->assertSee("We couldn't start the payment. Please try again.");
    });

    test('the message goes away once the order is paid', function () {
        $order = pendingStripeOrder();
        session()->flash('payment-status', "We couldn't start the payment. Please try again.");
        $page = Livewire::actingAs($order->user)->test('pages::orders.show', ['order' => $order]);

        $order->transitionTo(OrderStatus::Paid);

        $page->call('$refresh')->assertDontSee("We couldn't start the payment. Please try again.");
    });
});

describe('Simulate payment', function () {
    beforeEach(function () {
        $this->seed(RoleSeeder::class);
    });

    afterEach(function () {
        $this->app['env'] = 'testing';
    });

    test('an admin on local can mark their own pending order paid', function () {
        $this->app['env'] = 'local';
        $order = pendingStripeOrder(attributes: ['user_id' => userWithRole('admin')->id]);

        Livewire::actingAs($order->user)->test('pages::orders.show', ['order' => $order])
            ->assertSeeHtml('data-test="simulate-payment"')
            ->call('simulatePayment')
            ->assertOk();

        expect($order->fresh()->status)->toBe(OrderStatus::Paid)
            ->and($this->stripe->requests)->toBe([]);
    });

    test('is hidden and refused for anyone else', function (string $env, string $role) {
        $this->app['env'] = $env;
        $order = pendingStripeOrder(attributes: ['user_id' => userWithRole($role)->id]);

        Livewire::actingAs($order->user)->test('pages::orders.show', ['order' => $order])
            ->assertDontSeeHtml('data-test="simulate-payment"')
            ->call('simulatePayment')
            ->assertForbidden();

        expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    })->with([
        'admin, not local' => ['production', 'admin'],
        'admin, testing' => ['testing', 'admin'],
        'customer on local' => ['local', 'customer'],
        'manager on local' => ['local', 'manager'],
        'barista on local' => ['local', 'barista'],
    ]);
});

describe('configuration', function () {
    test('without PAYMENT_GATEWAY the fake gateway is used, so CI needs no Stripe keys', function () {
        // What phpunit.xml gives every test (this file's beforeEach switches to Stripe on top).
        expect(env('PAYMENT_GATEWAY'))->toBe('fake')
            ->and(env('STRIPE_SECRET'))->toBeEmpty()
            ->and(env('STRIPE_WEBHOOK_SECRET'))->toBeEmpty();

        config(['payment.gateway' => env('PAYMENT_GATEWAY')]);

        expect(app(PaymentGateway::class))->toBeInstanceOf(FakePaymentGateway::class);
    });

    test('"stripe" picks the Stripe gateway, even with no key configured', function () {
        config(['services.stripe.secret' => '']);
        app()->forgetInstance(StripeClient::class);

        expect(app(PaymentGateway::class))->toBeInstanceOf(StripePaymentGateway::class);
    });

    test('an unknown PAYMENT_GATEWAY fails loudly', function () {
        config(['payment.gateway' => 'paypal']);

        expect(fn () => app(PaymentGateway::class))->toThrow(InvalidArgumentException::class);
    });

    test('a test that forgets the fake cannot reach Stripe', function () {
        ApiRequestor::setHttpClient(new RefusingStripeHttpClient);

        expect(fn () => app(PaymentGateway::class)->startPayment(pendingStripeOrder()))
            ->toThrow(LogicException::class, 'A test tried to reach Stripe');
    });
});

describe('timeouts', function () {
    test('Stripe calls time out quickly, so the order lock is never held for long', function () {
        app()->forgetInstance(StripeClient::class);
        $client = app(StripeClient::class);

        expect(CurlClient::instance()->getConnectTimeout())->toBe(5)
            ->and(CurlClient::instance()->getTimeout())->toBe(10)
            ->and($client->getMaxNetworkRetries())->toBe(1)
            // Not the SDK's defaults (30 s to connect, 80 s per request).
            ->and(CurlClient::DEFAULT_TIMEOUT)->toBe(80);
    });

    test('configuring the timeouts does not replace a test\'s fake HTTP client', function () {
        app()->forgetInstance(StripeClient::class);
        app(StripeClient::class);

        expect(ApiRequestor::httpClient())->toBe($this->stripe);
    });
});
