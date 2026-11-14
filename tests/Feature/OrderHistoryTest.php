<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'America/Vancouver'));
    $this->user = User::factory()->create();
});

/**
 * One order for $user, placed $minutesAgo minutes ago, with items of the given quantities.
 *
 * @param  list<int>  $quantities
 */
function historyOrder(User $user, int $minutesAgo, array $quantities = [1], array $attributes = []): Order
{
    $order = Order::factory()->for($user)->create([...$attributes, 'created_at' => now()->subMinutes($minutesAgo)]);

    foreach ($quantities as $quantity) {
        OrderItem::factory()->for($order)->create(['quantity' => $quantity]);
    }

    return $order;
}

/**
 * Order numbers in the order the page lists them.
 *
 * @return list<string>
 */
function listedNumbers(string $html): array
{
    preg_match_all('/data-test="order-number"[^>]*>\s*(TA-\d+)/', $html, $matches);

    return $matches[1];
}

/**
 * The first request in a test also fills per-process caches (the role lookup behind the
 * header's Admin link). Make one request first so every measured request is warm.
 */
function historyWarmUp(object $test): void
{
    $test->get(route('orders.index'))->assertOk();
}

function countQueries(Closure $call): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $call();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

describe('access', function () {
    test('guests are sent to log in', function () {
        $this->get(route('orders.index'))->assertRedirect(route('login'));
    });

    test('unverified users are sent to verify their email', function () {
        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('orders.index'))
            ->assertRedirect(route('verification.notice'));
    });

    test('the header shows My orders only when logged in', function () {
        $this->get(route('home'))->assertDontSee('data-test="my-orders-link"', escape: false);

        $this->actingAs($this->user)->get(route('home'))
            ->assertSee('data-test="my-orders-link"', escape: false)
            ->assertSee('href="'.route('orders.index').'"', escape: false);
    });
});

describe('the list', function () {
    test('shows only my orders, newest first', function () {
        $old = historyOrder($this->user, 60);
        $new = historyOrder($this->user, 5);
        $middle = historyOrder($this->user, 30);
        $theirs = historyOrder(User::factory()->create(), 1);

        $html = $this->actingAs($this->user)->get(route('orders.index'))->assertOk()->getContent();

        expect(listedNumbers($html))->toBe([$new->order_number, $middle->order_number, $old->order_number])
            ->and($html)->not->toContain($theirs->order_number);
    });

    test('orders placed in the same second are newest id first', function () {
        $first = historyOrder($this->user, 10);
        $second = historyOrder($this->user, 10);

        expect(listedNumbers($this->actingAs($this->user)->get(route('orders.index'))->getContent()))
            ->toBe([$second->order_number, $first->order_number]);
    });

    test('each row shows the number, time, drink count, total, status, and links to the receipt', function () {
        $order = historyOrder($this->user, 90, [2, 3], ['total_cents' => 2293, 'status' => OrderStatus::Paid]);

        $this->actingAs($this->user)->get(route('orders.index'))
            ->assertSeeInOrder([$order->order_number, 'Nov 18, 2026 10:30 AM', '5 drinks', '$22.93', 'Paid'])
            ->assertSee('href="'.route('orders.show', $order).'"', escape: false);
    });

    test('one drink is singular', function () {
        historyOrder($this->user, 1, [1]);

        $this->actingAs($this->user)->get(route('orders.index'))->assertSee('1 drink')->assertDontSee('1 drinks');
    });

    test('a pending order says payment is pending', function () {
        historyOrder($this->user, 1);

        $this->actingAs($this->user)->get(route('orders.index'))->assertSee('Payment pending');
    });

    test('no orders shows an empty state', function () {
        $this->actingAs($this->user)->get(route('orders.index'))
            ->assertSee('data-test="no-orders"', escape: false);
    });

    test('10 orders per page, the rest on page 2', function () {
        $orders = collect(range(1, 12))->map(fn (int $i) => historyOrder($this->user, $i));

        $this->actingAs($this->user);

        expect(listedNumbers($this->get(route('orders.index'))->getContent()))
            ->toBe($orders->take(10)->pluck('order_number')->all())
            ->and(listedNumbers($this->get(route('orders.index', ['page' => 2]))->getContent()))
            ->toBe($orders->slice(10)->pluck('order_number')->values()->all());
    });
});

describe('queries', function () {
    test('the query count is the same for 3 orders and for 10', function () {
        $this->actingAs($this->user);
        historyWarmUp($this);

        foreach (range(1, 3) as $i) {
            historyOrder($this->user, $i, [1, 2]);
        }
        $forThree = countQueries(fn () => $this->get(route('orders.index'))->assertOk());

        foreach (range(4, 10) as $i) {
            historyOrder($this->user, $i, [1, 2, 3]);
        }
        $forTen = countQueries(fn () => $this->get(route('orders.index'))->assertOk());

        expect(listedNumbers($this->get(route('orders.index'))->getContent()))->toHaveCount(10)
            ->and($forTen)->toBe($forThree);
    });

    test('page 2 costs the same queries as page 1', function () {
        $this->actingAs($this->user);

        foreach (range(1, 15) as $i) {
            historyOrder($this->user, $i, [1, 1]);
        }
        historyWarmUp($this);

        expect(countQueries(fn () => $this->get(route('orders.index', ['page' => 2]))->assertOk()))
            ->toBe(countQueries(fn () => $this->get(route('orders.index'))->assertOk()));
    });

    test('the page itself runs two queries: the count and the orders with their drink totals', function () {
        $this->actingAs($this->user);
        historyOrder($this->user, 1, [2]);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->get(route('orders.index'))->assertOk();

        $orderQueries = collect($queries)->filter(fn (string $sql) => str_contains($sql, '"orders"') || str_contains($sql, '`orders`'))->values();

        expect($orderQueries)->toHaveCount(2)
            ->and($orderQueries[0])->toContain('count(*)')
            ->and($orderQueries[1])->toContain('sum(');
    });
});
