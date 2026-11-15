<?php

use App\Enums\OrderStatus;
use App\Models\MenuItem;
use App\Models\Order;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

const WEBHOOK_SECRET = 'whsec_test_secret';

beforeEach(function () {
    config(['services.stripe.webhook_secret' => WEBHOOK_SECRET, 'logging.default' => 'null']);

    $this->logged = [];
    Event::listen(MessageLogged::class, fn (MessageLogged $log) => $this->logged[] = $log);
});

/**
 * A Stripe event for a Checkout Session, as JSON.
 *
 * @param  array<string, mixed>  $session
 */
function stripeEvent(string $type, array $session): string
{
    return json_encode([
        'id' => 'evt_'.Str::random(16),
        'object' => 'event',
        'type' => $type,
        'api_version' => '2025-01-01',
        'created' => time(),
        'data' => ['object' => $session],
    ], JSON_THROW_ON_ERROR);
}

/**
 * The Stripe-Signature header for $payload: "t=<time>,v1=<HMAC-SHA256 of '<time>.<payload>'>".
 */
function stripeSignature(string $payload, string $secret = WEBHOOK_SECRET, ?int $time = null): string
{
    $time ??= time();

    return "t={$time},v1=".hash_hmac('sha256', "{$time}.{$payload}", $secret);
}

function postWebhook(string $payload, ?string $signature): TestResponse
{
    $headers = ['CONTENT_TYPE' => 'application/json'];

    if ($signature !== null) {
        $headers['HTTP_STRIPE_SIGNATURE'] = $signature;
    }

    return test()->call('POST', '/stripe/webhook', [], [], [], $headers, $payload);
}

/**
 * A pending order with a current Checkout Session, and that Session as Stripe sends it
 * when it's paid.
 *
 * @return array{0: Order, 1: array<string, mixed>}
 */
function paidSessionFor(array $orderAttributes = []): array
{
    $order = Order::factory()->create(['stripe_session_id' => 'cs_test_'.Str::random(24), 'total_cents' => 1312, ...$orderAttributes]);

    return [$order, stripeSession([
        'id' => $order->stripe_session_id,
        'status' => 'complete',
        'payment_status' => 'paid',
        'amount_total' => 1312,
        'currency' => 'cad',
        'metadata' => ['order_id' => (string) $order->id],
        'url' => null,
    ])];
}

function sendEvent(string $type, array $session): TestResponse
{
    $payload = stripeEvent($type, $session);

    return postWebhook($payload, stripeSignature($payload));
}

describe('signature', function () {
    test('a correctly signed event is accepted', function () {
        [, $session] = paidSessionFor();

        sendEvent('checkout.session.completed', $session)->assertOk();
    });

    test('anything not signed with the webhook secret is rejected with 400 and changes nothing', function (Closure $request) {
        [$order, $session] = paidSessionFor();
        $payload = stripeEvent('checkout.session.completed', $session);

        $request($payload)->assertStatus(400);

        expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    })->with([
        'no signature' => fn (string $payload) => postWebhook($payload, null),
        'another secret' => fn (string $payload) => postWebhook($payload, stripeSignature($payload, 'whsec_attacker')),
        'body changed after signing' => fn (string $payload) => postWebhook(str_replace('1312', '1', $payload), stripeSignature($payload)),
        'signed over 5 minutes ago (replay)' => fn (string $payload) => postWebhook($payload, stripeSignature($payload, time: time() - 301)),
        'not JSON' => fn (string $payload) => postWebhook('not json', stripeSignature('not json')),
    ]);

    test('with no STRIPE_WEBHOOK_SECRET configured every event is rejected, and that is logged', function () {
        config(['services.stripe.webhook_secret' => '']);
        [$order, $session] = paidSessionFor();

        sendEvent('checkout.session.completed', $session)->assertStatus(400);

        expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment)
            ->and($this->logged[0]->message)->toContain('STRIPE_WEBHOOK_SECRET');
    });

    test('needs no login, session, or CSRF token, and sets no cookie', function () {
        [, $session] = paidSessionFor();

        $response = sendEvent('checkout.session.completed', $session)->assertOk();

        expect($response->headers->getCookies())->toBe([]);
    });
});

describe('checkout.session.completed', function () {
    test('marks the order paid', function () {
        [$order, $session] = paidSessionFor();

        sendEvent('checkout.session.completed', $session)->assertOk();

        expect($order->fresh()->status)->toBe(OrderStatus::Paid)
            ->and($order->fresh()->paid_at)->not->toBeNull()
            ->and($this->logged)->toBe([]);
    });

    test('sent twice: paid once, 200 both times', function () {
        [$order, $session] = paidSessionFor();
        sendEvent('checkout.session.completed', $session)->assertOk();
        $paidAt = $order->fresh()->paid_at;

        $this->travel(1)->minutes();
        sendEvent('checkout.session.completed', $session)->assertOk();

        expect($order->fresh()->status)->toBe(OrderStatus::Paid)
            ->and($order->fresh()->paid_at->equalTo($paidAt))->toBeTrue()
            ->and($this->logged)->toBe([]);
    });

    test('for an order the bar already started, changes nothing', function () {
        [$order, $session] = paidSessionFor(['status' => OrderStatus::Preparing]);

        sendEvent('checkout.session.completed', $session)->assertOk();

        expect($order->fresh()->status)->toBe(OrderStatus::Preparing);
    });

    test('still marks the order paid when a drink was sold out after it was placed', function () {
        [$order, $session] = paidSessionFor();
        MenuItem::query()->update(['is_available' => false]);

        sendEvent('checkout.session.completed', $session)->assertOk();

        expect($order->fresh()->status)->toBe(OrderStatus::Paid);
    });
});

/*
 * An event that can't be applied must still get 200 (anything else makes Stripe resend it
 * for days), change nothing, and leave a log line for anything a human should look at.
 */
describe('events that cannot be applied: always 200, nothing changes', function () {
    test('every case', function (string $type, Closure $session, Closure $order, ?string $level) {
        [$base, $paid] = paidSessionFor();
        $target = $order($base);
        $before = $target->fresh()->getAttributes();

        sendEvent($type, $session($paid, $base))->assertOk();

        expect($target->fresh()->getAttributes())->toBe($before)
            ->and(array_map(fn (MessageLogged $log) => $log->level, $this->logged))->toBe($level === null ? [] : [$level]);
    })->with([
        'amount does not match the order' => [
            'checkout.session.completed', fn (array $s) => [...$s, 'amount_total' => 1311], fn (Order $o) => $o, 'error',
        ],
        'currency is not CAD' => [
            'checkout.session.completed', fn (array $s) => [...$s, 'currency' => 'usd'], fn (Order $o) => $o, 'error',
        ],
        'completed, but the payment is still on its way (delayed method)' => [
            'checkout.session.completed', fn (array $s) => [...$s, 'payment_status' => 'unpaid'], fn (Order $o) => $o, 'info',
        ],
        'a delayed payment that failed' => [
            'checkout.session.async_payment_failed', fn (array $s) => [...$s, 'payment_status' => 'unpaid'], fn (Order $o) => $o, null,
        ],
        'session belongs to no order' => [
            'checkout.session.completed', fn (array $s) => [...$s, 'id' => 'cs_test_unknown'], fn (Order $o) => $o, 'warning',
        ],
        'metadata names another order' => [
            'checkout.session.completed', fn (array $s) => [...$s, 'metadata' => ['order_id' => '999999']], fn (Order $o) => $o, 'warning',
        ],
        'no metadata at all' => [
            'checkout.session.completed', fn (array $s) => [...$s, 'metadata' => []], fn (Order $o) => $o, 'warning',
        ],
        'order was cancelled (paid too late: needs a refund)' => [
            'checkout.session.completed', fn (array $s) => $s,
            function (Order $o) {
                $o->transitionTo(OrderStatus::Cancelled);

                return $o;
            }, 'error',
        ],
        'an old Session after a retry (expired event)' => [
            'checkout.session.expired', fn (array $s) => [...$s, 'id' => 'cs_test_replaced', 'status' => 'expired'], fn (Order $o) => $o, 'warning',
        ],
        'expired, but the order is already paid' => [
            'checkout.session.expired', fn (array $s) => [...$s, 'status' => 'expired'],
            function (Order $o) {
                $o->transitionTo(OrderStatus::Paid);

                return $o;
            }, null,
        ],
        'an event type we do not handle' => [
            'checkout.session.updated', fn (array $s) => $s, fn (Order $o) => $o, null,
        ],
        'not about a Checkout Session' => [
            'charge.refunded', fn (array $s) => ['id' => 'ch_test_1', 'object' => 'charge'], fn (Order $o) => $o, null,
        ],
    ]);

    test('losing a race to another delivery that cancelled the order: 200, logged, still cancelled', function () {
        [$order, $session] = paidSessionFor();

        // Right after the webhook reads the order, something else cancels it.
        $raced = false;
        Order::retrieved(function (Order $loaded) use (&$raced) {
            if (! $raced) {
                $raced = true;
                DB::table('orders')->where('id', $loaded->id)->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            }
        });

        sendEvent('checkout.session.completed', $session)->assertOk();

        expect($raced)->toBeTrue()
            ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled)
            ->and($this->logged)->toHaveCount(1)
            ->and($this->logged[0]->level)->toBe('error')
            ->and($this->logged[0]->message)->toContain('needs a refund');
    });

    test('losing a race to a parallel delivery that already paid it: 200, nothing logged', function () {
        [$order, $session] = paidSessionFor();

        $raced = false;
        Order::retrieved(function (Order $loaded) use (&$raced) {
            if (! $raced) {
                $raced = true;
                DB::table('orders')->where('id', $loaded->id)->update(['status' => 'paid', 'paid_at' => now()]);
            }
        });

        sendEvent('checkout.session.completed', $session)->assertOk();

        expect($order->fresh()->status)->toBe(OrderStatus::Paid)
            ->and($this->logged)->toBe([]);
    });

    test('log lines carry ids and amounts only, no customer data', function () {
        [$order, $session] = paidSessionFor();

        sendEvent('checkout.session.completed', [...$session, 'amount_total' => 1, 'customer_details' => ['email' => 'mei@example.com']]);

        expect($this->logged[0]->context)->toBe([
            'order_id' => $order->id,
            'session_id' => $session['id'],
            'order_total_cents' => 1312,
            'stripe_amount_total' => 1,
            'stripe_currency' => 'cad',
        ]);
    });
});

describe('delayed payment methods', function () {
    test('async_payment_succeeded marks the order paid, with the same checks as completed', function () {
        [$order, $session] = paidSessionFor();
        sendEvent('checkout.session.completed', [...$session, 'payment_status' => 'unpaid'])->assertOk();

        expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);

        sendEvent('checkout.session.async_payment_succeeded', $session)->assertOk();

        expect($order->fresh()->status)->toBe(OrderStatus::Paid);
    });

    test('async_payment_succeeded with the wrong amount does not mark it paid', function () {
        [$order, $session] = paidSessionFor();

        sendEvent('checkout.session.async_payment_succeeded', [...$session, 'amount_total' => 1])->assertOk();

        expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment)
            ->and($this->logged[0]->level)->toBe('error');
    });
});

describe('checkout.session.expired', function () {
    test('cancels the pending order', function () {
        [$order, $session] = paidSessionFor();

        sendEvent('checkout.session.expired', [...$session, 'status' => 'expired', 'payment_status' => 'unpaid'])->assertOk();

        expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
            ->and($order->fresh()->cancelled_at)->not->toBeNull();
    });
});
