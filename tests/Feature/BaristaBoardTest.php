<?php

use App\Enums\OrderStatus;
use App\Enums\PickupType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Middleware\RoleMiddleware;

const BOARD = 'pages::barista-board';

/*
 * The clock is Sat Nov 14, 2026 23:00 in Vancouver, which is already Nov 15 in UTC, so a
 * board that used the UTC date would show the wrong day.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-14 23:00:00', 'America/Vancouver'));
    $this->barista = userWithRole('barista');
});

function boardTime(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'America/Vancouver');
}

/**
 * A board order with one drink and two options, picked up at $pickupAt.
 *
 * @param  array<string, mixed>  $attributes
 */
function boardOrder(OrderStatus $status, CarbonImmutable $pickupAt, array $attributes = []): Order
{
    $order = Order::factory()->create([
        'status' => $status,
        'pickup_at' => $pickupAt->utc(),
        'prep_minutes' => 4,
        ...$attributes,
    ]);

    $item = OrderItem::factory()->for($order)->create(['name_en' => 'Classic Milk Tea', 'name_zh' => '經典奶茶', 'quantity' => 2]);
    OrderItemModifier::factory()->for($item)->create(['group_name_en' => 'Sugar', 'group_name_zh' => '甜度', 'name_en' => 'Half', 'name_zh' => '半糖']);
    OrderItemModifier::factory()->for($item)->create(['group_name_en' => 'Toppings', 'group_name_zh' => '加料', 'name_en' => 'Pearls', 'name_zh' => '珍珠']);

    return $order;
}

/**
 * The order numbers in one column, top to bottom.
 *
 * @return list<string>
 */
function columnOrders(string $html, OrderStatus $status): array
{
    expect(preg_match('/data-test="column-'.$status->value.'".*?<\/section>/s', $html, $column))->toBe(1);
    preg_match_all('/data-order="([^"]+)"/', $column[0], $numbers);

    return $numbers[1];
}

describe('access', function () {
    test('customers cannot open the board', function () {
        Livewire::actingAs(userWithRole('customer'))->test(BOARD)->assertForbidden();
    });

    test('staff can open the board', function (string $role) {
        Livewire::actingAs(userWithRole($role))->test(BOARD)->assertOk()->assertSee('Barista board');
    })->with(['barista', 'manager', 'admin']);

    test('a customer calling a board action is blocked and nothing changes', function (string $action, OrderStatus $from) {
        $order = boardOrder($from, boardTime('2026-11-14 23:10'));
        // Mounted by a barista, then replayed by a customer: each action checks on its own.
        $board = Livewire::actingAs($this->barista)->test(BOARD);
        $this->actingAs(userWithRole('customer'));

        $board->call($action, $order->id)->assertForbidden();

        expect($order->fresh()->status)->toBe($from);
    })->with([
        'start' => ['start', OrderStatus::Paid],
        'bump' => ['bump', OrderStatus::Preparing],
        'recall' => ['recall', OrderStatus::Ready],
        'pickUp' => ['pickUp', OrderStatus::Ready],
    ]);

    test('every staff role can advance an order', function (string $role) {
        $order = boardOrder(OrderStatus::Paid, boardTime('2026-11-14 23:10'));

        Livewire::actingAs(userWithRole($role))->test(BOARD)->call('start', $order->id)->assertOk();

        expect($order->fresh()->status)->toBe(OrderStatus::Preparing);
    })->with(['barista', 'manager', 'admin']);

    test('the policy never lets staff mark an order paid or cancelled', function (OrderStatus $to, bool $allowed) {
        $order = boardOrder(OrderStatus::Paid, boardTime('2026-11-14 23:10'));

        expect($this->barista->can('advance', [$order, $to]))->toBe($allowed);
    })->with([
        [OrderStatus::Preparing, true],
        [OrderStatus::Ready, true],
        [OrderStatus::PickedUp, true],
        [OrderStatus::Paid, false],
        [OrderStatus::Cancelled, false],
        [OrderStatus::PendingPayment, false],
    ]);

    test('an unknown order id is a 404', function () {
        Livewire::actingAs($this->barista)->test(BOARD)->call('start', 999999)->assertNotFound();
    });

    test('Livewire re-applies the role middleware to the board\'s actions', function () {
        expect(Livewire::getPersistentMiddleware())->toContain(RoleMiddleware::class);
    });

    test('no order data is stored as component state', function () {
        boardOrder(OrderStatus::Paid, boardTime('2026-11-14 23:10'));

        $board = Livewire::actingAs($this->barista)->test(BOARD);

        expect($board->instance()->all())->toBe([]);
    });
});

describe('what the board shows', function () {
    test('only today\'s (Vancouver date) paid, preparing, and ready orders, each column by pickup time', function () {
        $paidLate = boardOrder(OrderStatus::Paid, boardTime('2026-11-14 23:50'));
        $paidEarly = boardOrder(OrderStatus::Paid, boardTime('2026-11-14 00:05'));  // Nov 14 07:05 UTC
        $preparing = boardOrder(OrderStatus::Preparing, boardTime('2026-11-14 22:55'));
        $ready = boardOrder(OrderStatus::Ready, boardTime('2026-11-14 22:40'));

        $hidden = [
            boardOrder(OrderStatus::Paid, boardTime('2026-11-15 00:10')),    // tomorrow in Vancouver, though the same UTC date as now
            boardOrder(OrderStatus::Paid, boardTime('2026-11-13 23:59')),    // yesterday
            boardOrder(OrderStatus::PendingPayment, boardTime('2026-11-14 23:10')),
            boardOrder(OrderStatus::PickedUp, boardTime('2026-11-14 23:10')),
            boardOrder(OrderStatus::Cancelled, boardTime('2026-11-14 23:10')),
        ];

        $html = Livewire::actingAs($this->barista)->test(BOARD)->html();

        expect(columnOrders($html, OrderStatus::Paid))->toBe([$paidEarly->order_number, $paidLate->order_number])
            ->and(columnOrders($html, OrderStatus::Preparing))->toBe([$preparing->order_number])
            ->and(columnOrders($html, OrderStatus::Ready))->toBe([$ready->order_number]);

        foreach ($hidden as $order) {
            expect($html)->not->toContain($order->order_number);
        }
    });

    test('ready orders from earlier days stay in the Ready column, oldest first; paid and preparing do not', function () {
        // T2-5 (#38): a drink nobody collected doesn't vanish from the board at midnight.
        $readyToday = boardOrder(OrderStatus::Ready, boardTime('2026-11-14 22:40'));
        $readyYesterday = boardOrder(OrderStatus::Ready, boardTime('2026-11-13 15:00'));
        $readyLastWeek = boardOrder(OrderStatus::Ready, boardTime('2026-11-07 12:30'));
        $paidYesterday = boardOrder(OrderStatus::Paid, boardTime('2026-11-13 15:00'));
        $preparingYesterday = boardOrder(OrderStatus::Preparing, boardTime('2026-11-13 15:00'));

        $html = Livewire::actingAs($this->barista)->test(BOARD)->html();

        expect(columnOrders($html, OrderStatus::Ready))
            ->toBe([$readyLastWeek->order_number, $readyYesterday->order_number, $readyToday->order_number])
            ->and($html)->not->toContain($paidYesterday->order_number)
            ->and($html)->not->toContain($preparingYesterday->order_number);
    });

    test('a leftover ready order shows its day as well as its time', function () {
        boardOrder(OrderStatus::Ready, boardTime('2026-11-13 15:00'));  // Friday
        boardOrder(OrderStatus::Ready, boardTime('2026-11-14 22:40'));  // today

        Livewire::actingAs($this->barista)->test(BOARD)
            ->assertSee('Fri 3:00 PM')
            ->assertSee('10:40 PM')
            ->assertDontSee('Sat 10:40 PM');
    });

    test('a card shows the order number, pickup time, drinks with quantities and options', function () {
        $order = boardOrder(OrderStatus::Paid, boardTime('2026-11-14 23:15'));

        Livewire::actingAs($this->barista)->test(BOARD)
            ->assertSee($order->order_number)
            ->assertSee('11:15 PM')
            ->assertSeeHtml('<span class="tabular-nums">2 ×</span> Classic Milk Tea')
            ->assertSee('Sugar: Half')
            ->assertSee('Toppings: Pearls')
            ->assertDontSeeHtml('data-test="scheduled-badge"')
            ->assertDontSeeHtml('data-test="recalled-badge"');
    });

    test('scheduled and recalled orders are marked', function () {
        boardOrder(OrderStatus::Preparing, boardTime('2026-11-14 23:30'), ['pickup_type' => PickupType::Scheduled, 'recalled_count' => 2]);

        Livewire::actingAs($this->barista)->test(BOARD)
            ->assertSeeHtml('data-test="scheduled-badge"')
            ->assertSee('Recalled ×2');
    });

    test('the timer gets its thresholds from the server: pickup time, then pickup + half the prep time', function () {
        $pickup = boardTime('2026-11-14 23:15');
        boardOrder(OrderStatus::Paid, $pickup);  // prep 4 minutes: red 2 minutes after pickup

        Livewire::actingAs($this->barista)->test(BOARD)
            ->assertSeeHtml('pickup: '.$pickup->getTimestampMs().', overdue: '.$pickup->addMinutes(2)->getTimestampMs())
            ->assertSeeHtml('now: '.now()->getTimestampMs());
    });

    test('ready cards have no late colours', function () {
        boardOrder(OrderStatus::Ready, boardTime('2026-11-14 22:40'), ['ready_at' => now()->subMinutes(3)]);

        $html = Livewire::actingAs($this->barista)->test(BOARD)->html();
        preg_match('/data-test="column-ready".*?<\/section>/s', $html, $column);

        expect($column[0])->not->toContain('overdue')
            ->and($column[0])->toContain('since: '.now()->subMinutes(3)->getTimestampMs());
    });

    test('each column has its own buttons', function () {
        boardOrder(OrderStatus::Paid, boardTime('2026-11-14 23:10'));
        boardOrder(OrderStatus::Preparing, boardTime('2026-11-14 23:10'));
        boardOrder(OrderStatus::Ready, boardTime('2026-11-14 23:10'));

        $html = Livewire::actingAs($this->barista)->test(BOARD)->html();

        foreach ([
            'paid' => ['start-button'],
            'preparing' => ['bump-button'],
            'ready' => ['recall-button', 'picked-up-button'],
        ] as $column => $buttons) {
            preg_match('/data-test="column-'.$column.'".*?<\/section>/s', $html, $section);
            preg_match_all('/data-test="(\w+(?:-\w+)*-button)"/', $section[0], $found);

            expect($found[1])->toBe($buttons);
        }
    });

    test('an empty board says so in each column', function () {
        Livewire::actingAs($this->barista)->test(BOARD)
            ->assertSeeHtmlInOrder(['data-test="column-paid"', 'No orders', 'data-test="column-preparing"', 'No orders', 'data-test="column-ready"', 'No orders']);
    });

    test('has the Live / Polling indicator for the barista channel instead of a fixed wire:poll', function () {
        Livewire::actingAs($this->barista)->test(BOARD)
            ->assertSeeHtml('data-test="realtime-status"')
            ->assertSeeHtml('data-channel="private-barista"')
            ->assertDontSeeHtml('wire:poll');
    });

    test('an OrderStatusChanged event on the barista channel redraws the board from the database', function () {
        $order = boardOrder(OrderStatus::Paid, boardTime('2026-11-14 23:15'));
        $board = Livewire::actingAs($this->barista)->test(BOARD);
        expect(columnOrders($board->html(), OrderStatus::Paid))->toBe([$order->order_number]);

        $order->transitionTo(OrderStatus::Preparing);
        $board->dispatch('echo-private:barista,OrderStatusChanged', ['order_id' => $order->id, 'status' => 'preparing']);

        expect(columnOrders($board->html(), OrderStatus::Paid))->toBe([])
            ->and(columnOrders($board->html(), OrderStatus::Preparing))->toBe([$order->order_number]);
    });

    test('is in Traditional Chinese when the locale is zh_TW', function () {
        app()->setLocale('zh_TW');
        boardOrder(OrderStatus::Paid, boardTime('2026-11-14 23:15'));

        Livewire::actingAs($this->barista)->test(BOARD)
            ->assertSee('吧台看板')
            ->assertSee('經典奶茶')
            ->assertSee('甜度：半糖')
            ->assertSee('開始')
            ->assertSee('晚上 11:15');
    });

    test('the number of queries does not grow with the number of orders', function () {
        $board = Livewire::actingAs($this->barista)->test(BOARD);

        $queriesFor = function (int $orders) use ($board): int {
            while (Order::count() < $orders) {
                boardOrder(OrderStatus::Paid, boardTime('2026-11-14 23:10'));
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            $board->call('$refresh');
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $three = $queriesFor(3);
        $ten = $queriesFor(10);

        expect($board->html())->toContain(Order::latest('id')->value('order_number'))
            ->and($ten)->toBe($three)
            ->and($three)->toBe(3);  // orders, items, modifiers
    });

    test('orders with several drinks each redraw on a refresh without lazy loading (#41)', function () {
        $orders = collect(range(1, 3))->map(fn () => boardOrder(OrderStatus::Paid, boardTime('2026-11-14 23:10')));
        $orders->each(fn (Order $order) => OrderItemModifier::factory()
            ->for(OrderItem::factory()->for($order)->create(['name_en' => 'Second Drink '.$order->id]))
            ->create(['group_name_en' => 'Ice', 'name_en' => 'Less']));

        $board = Livewire::actingAs($this->barista)->test(BOARD);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $board->call('$refresh');
        DB::disableQueryLog();

        $html = $board->html();

        expect(count(DB::getQueryLog()))->toBe(3)
            ->and(substr_count($html, 'Classic Milk Tea'))->toBe(3)
            ->and(substr_count($html, 'Ice: Less'))->toBe(3);
        $orders->each(fn (Order $order) => expect($html)->toContain('Second Drink '.$order->id));
    });
});

describe('actions', function () {
    test('Start → Bump → Recall → Bump → Picked up', function () {
        $order = boardOrder(OrderStatus::Paid, boardTime('2026-11-14 23:10'));
        $board = Livewire::actingAs($this->barista)->test(BOARD);

        $board->call('start', $order->id);
        expect(columnOrders($board->html(), OrderStatus::Preparing))->toBe([$order->order_number]);

        $board->call('bump', $order->id);
        expect(columnOrders($board->html(), OrderStatus::Ready))->toBe([$order->order_number]);

        $board->call('recall', $order->id);
        expect(columnOrders($board->html(), OrderStatus::Preparing))->toBe([$order->order_number])
            ->and($board->html())->toContain('Recalled ×1');

        $board->call('bump', $order->id);
        $board->call('pickUp', $order->id);

        $fresh = $order->fresh();
        expect($fresh->status)->toBe(OrderStatus::PickedUp)
            ->and($fresh->recalled_count)->toBe(1)
            ->and($fresh->picked_up_at)->not->toBeNull()
            ->and($board->html())->not->toContain($order->order_number);

        $board->assertNotDispatched('toast-show');
    });

    test('a button for an order someone else already moved shows a toast and changes nothing', function (string $action, OrderStatus $now) {
        $order = boardOrder($now, boardTime('2026-11-14 23:10'));

        Livewire::actingAs($this->barista)->test(BOARD)
            ->call($action, $order->id)
            ->assertOk()
            ->assertDispatched('toast-show', fn (string $event, array $params) => $params['slots']['text'] === 'This order was already updated.');

        expect($order->fresh()->status)->toBe($now)
            ->and($order->fresh()->recalled_count)->toBe(0);
    })->with([
        // A stale Start on an order that is now ready must not recall it.
        'Start on a ready order' => ['start', OrderStatus::Ready],
        'Start on a preparing order' => ['start', OrderStatus::Preparing],
        'Bump on a ready order' => ['bump', OrderStatus::Ready],
        'Recall on a picked-up order' => ['recall', OrderStatus::PickedUp],
        'Picked up on a picked-up order' => ['pickUp', OrderStatus::PickedUp],
        'Start on a cancelled order' => ['start', OrderStatus::Cancelled],
    ]);

    test('losing a race between loading the order and writing it shows a toast, not a 500', function () {
        $order = boardOrder(OrderStatus::Paid, boardTime('2026-11-14 23:10'));
        $board = Livewire::actingAs($this->barista)->test(BOARD);

        // The other tablet starts the order right after this request loads it.
        $raced = false;
        Order::retrieved(function (Order $loaded) use (&$raced) {
            if (! $raced && $loaded->status === OrderStatus::Paid) {
                $raced = true;
                DB::table('orders')->where('id', $loaded->id)->update(['status' => 'preparing', 'started_at' => now()]);
            }
        });

        $board->call('start', $order->id)
            ->assertOk()
            ->assertDispatched('toast-show');

        expect($raced)->toBeTrue()
            ->and($order->fresh()->status)->toBe(OrderStatus::Preparing)
            // Drawn again from the database: the card is already in the Preparing column.
            ->and(columnOrders($board->html(), OrderStatus::Preparing))->toBe([$order->order_number]);
    });
});
