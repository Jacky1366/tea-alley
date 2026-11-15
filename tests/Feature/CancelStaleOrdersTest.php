<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;

/**
 * A pending order whose current Checkout Session expired $minutesAgo minutes ago (negative:
 * still open), or with no Session, placed $placedMinutesAgo minutes ago.
 */
function pendingOrderExpiring(?int $minutesAgo, int $placedMinutesAgo = 60): Order
{
    return Order::factory()->create([
        'payment_expires_at' => $minutesAgo === null ? null : now()->subMinutes($minutesAgo),
        'created_at' => now()->subMinutes($placedMinutesAgo),
    ]);
}

test('cancels a pending order more than 5 minutes after its Session expired', function () {
    $order = pendingOrderExpiring(6);

    $this->artisan('orders:cancel-stale')->expectsOutput('Cancelled 1 stale order.')->assertSuccessful();

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->fresh()->cancelled_at)->not->toBeNull();
});

test('leaves an order whose Session is still open or only just expired', function (int $minutesAgo) {
    $order = pendingOrderExpiring($minutesAgo);

    $this->artisan('orders:cancel-stale')->expectsOutput('Cancelled 0 stale orders.');

    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
})->with([
    'open for 20 more minutes' => -20,
    'expired 4 minutes ago (grace)' => 4,
]);

test('an order placed long ago but retried with a fresh Session is not cancelled', function () {
    $order = pendingOrderExpiring(-25, placedMinutesAgo: 90);

    $this->artisan('orders:cancel-stale');

    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

test('an order with no Session is cancelled 35 minutes after it was placed', function (int $placedMinutesAgo, OrderStatus $expected) {
    $order = pendingOrderExpiring(null, $placedMinutesAgo);

    $this->artisan('orders:cancel-stale');

    expect($order->fresh()->status)->toBe($expected);
})->with([
    'placed 36 minutes ago' => [36, OrderStatus::Cancelled],
    'placed 34 minutes ago' => [34, OrderStatus::PendingPayment],
]);

test('never touches an order that is not waiting for payment', function (string $state) {
    $order = Order::factory()->{$state}()->create(['payment_expires_at' => now()->subHour(), 'created_at' => now()->subHours(2)]);
    $before = $order->fresh()->getAttributes();

    $this->artisan('orders:cancel-stale');

    expect($order->fresh()->getAttributes())->toBe($before);
})->with(['paid', 'preparing', 'ready', 'pickedUp', 'cancelled']);

test('cancels every stale order in one run', function () {
    $orders = collect(range(1, 5))->map(fn () => pendingOrderExpiring(10));

    $this->artisan('orders:cancel-stale')->expectsOutput('Cancelled 5 stale orders.');

    expect($orders->map(fn (Order $o) => $o->fresh()->status)->unique()->all())->toBe([OrderStatus::Cancelled]);
});

test('is scheduled every 5 minutes', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $e) => str_contains((string) $e->command, 'orders:cancel-stale'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *');
});
