<?php

use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Exceptions\OrderTransitionConflict;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->freezeSecond();
    $this->earlier = now()->subMinutes(10);
});

/**
 * An order in $status, read back from the database. Every timestamp starts at "10 minutes
 * ago" (or null), so any column a transition writes shows up as a change.
 */
function orderIn(OrderStatus $status, object $t): Order
{
    return Order::factory()->create([
        'status' => $status,
        'paid_at' => $t->earlier,
        'started_at' => $t->earlier,
        'ready_at' => $t->earlier,
        'picked_up_at' => null,
        'cancelled_at' => null,
        'created_at' => $t->earlier,
        'updated_at' => $t->earlier,
    ])->fresh();
}

/**
 * The columns whose stored value differs between two reads of the same row.
 *
 * @return list<string>
 */
function changedColumns(Order $before, Order $after): array
{
    $old = $before->getAttributes();
    $new = $after->getAttributes();

    return array_values(array_filter(array_keys($new), fn (string $column) => $new[$column] !== $old[$column]));
}

/**
 * What each legal move writes, besides status and updated_at.
 */
const TRANSITION_COLUMNS = [
    'pending_payment>paid' => ['paid_at'],
    'pending_payment>cancelled' => ['cancelled_at'],
    'paid>preparing' => ['started_at'],
    'preparing>ready' => ['ready_at'],
    'ready>preparing' => ['ready_at', 'recalled_count'],  // ready_at cleared, recall counted
    'ready>picked_up' => ['picked_up_at'],
];

test('every (from, to) pair: legal moves write the right columns, illegal ones throw and write nothing', function (OrderStatus $from, OrderStatus $to) {
    $order = orderIn($from, $this);
    $before = $order->fresh();

    if (! isLegalOrderTransition($from, $to)) {
        expect(fn () => $order->transitionTo($to))->toThrow(InvalidOrderTransition::class);

        expect(changedColumns($before, $order->fresh()))->toBe([])
            ->and($order->status)->toBe($from);

        return;
    }

    $order->transitionTo($to);
    $after = $order->fresh();
    $written = TRANSITION_COLUMNS["{$from->value}>{$to->value}"];

    expect(changedColumns($before, $after))->toEqualCanonicalizing([...$written, 'status', 'updated_at'])
        ->and($after->status)->toBe($to)
        ->and($after->updated_at->equalTo(now()))->toBeTrue()
        // The model in memory matches the row and has nothing left to save.
        ->and($order->status)->toBe($to)
        ->and($order->isDirty())->toBeFalse();

    if ($from === OrderStatus::Ready && $to === OrderStatus::Preparing) {
        expect($after->ready_at)->toBeNull()
            ->and($after->recalled_count)->toBe(1);
    } else {
        expect($after->{$written[0]}->equalTo(now()))->toBeTrue();
    }
})->with('every status pair');

describe('conflicts', function () {
    test('a status changed by another request since the order was loaded throws a conflict', function () {
        $order = orderIn(OrderStatus::Paid, $this);

        // Another request starts the order first.
        DB::table('orders')->where('id', $order->id)->update(['status' => 'preparing', 'started_at' => now()->subMinute()]);

        expect(fn () => $order->transitionTo(OrderStatus::Preparing))->toThrow(OrderTransitionConflict::class);

        // The other request's write stands, and the stale model is left as it was.
        expect($order->fresh()->started_at->equalTo(now()->subMinute()))->toBeTrue()
            ->and($order->status)->toBe(OrderStatus::Paid)
            ->and($order->isDirty())->toBeFalse();
    });

    test('of two copies loaded at the same time, only the first move succeeds', function () {
        $order = orderIn(OrderStatus::Preparing, $this);
        $tabletA = Order::findOrFail($order->id);
        $tabletB = Order::findOrFail($order->id);

        $tabletA->transitionTo(OrderStatus::Ready);

        expect(fn () => $tabletB->transitionTo(OrderStatus::Ready))->toThrow(OrderTransitionConflict::class);
    });

    test('a move to the same status never reaches the database', function () {
        // On MySQL an UPDATE that changes nothing reports 0 rows, which would look like a conflict.
        $order = orderIn(OrderStatus::Paid, $this);
        DB::enableQueryLog();

        expect(fn () => $order->transitionTo(OrderStatus::Paid))->toThrow(InvalidOrderTransition::class);

        expect(DB::getQueryLog())->toBe([]);
    });

    test('only the transition columns are written; other unsaved changes stay unsaved', function () {
        $order = orderIn(OrderStatus::Paid, $this);
        $order->promo_code = 'NOT-SAVED';

        $order->transitionTo(OrderStatus::Preparing);

        expect($order->isDirty('promo_code'))->toBeTrue()
            ->and($order->isDirty('status'))->toBeFalse()
            ->and($order->fresh()->promo_code)->toBeNull();
    });
});

describe('recall', function () {
    test('clears ready_at, keeps started_at, and counts the recall', function () {
        $order = orderIn(OrderStatus::Paid, $this);
        $order->transitionTo(OrderStatus::Preparing);
        $startedAt = $order->fresh()->started_at;

        $this->travel(3)->minutes();
        $order->transitionTo(OrderStatus::Ready);
        $this->travel(1)->minutes();
        $order->transitionTo(OrderStatus::Preparing);

        $fresh = $order->fresh();
        expect($fresh->status)->toBe(OrderStatus::Preparing)
            ->and($fresh->ready_at)->toBeNull()
            ->and($fresh->started_at->equalTo($startedAt))->toBeTrue()
            ->and($fresh->recalled_count)->toBe(1)
            ->and($order->recalled_count)->toBe(1);

        // Bump again, recall again.
        $this->travel(2)->minutes();
        $order->transitionTo(OrderStatus::Ready);
        expect($order->fresh()->ready_at->equalTo(now()))->toBeTrue();

        $order->transitionTo(OrderStatus::Preparing);
        expect($order->fresh()->recalled_count)->toBe(2)
            ->and($order->fresh()->started_at->equalTo($startedAt))->toBeTrue();
    });

    test('counts in the database, not from a stale model', function () {
        $order = orderIn(OrderStatus::Ready, $this);
        $stale = Order::findOrFail($order->id);

        // Another tablet recalls and bumps it: ready again, with one recall already counted.
        $order->transitionTo(OrderStatus::Preparing);
        $order->transitionTo(OrderStatus::Ready);

        // The stale model still says ready, so the move is legal and matches the row.
        $stale->transitionTo(OrderStatus::Preparing);

        expect($order->fresh()->recalled_count)->toBe(2)
            ->and($stale->recalled_count)->toBe(2);
    });
});

test('board timer thresholds: late at pickup, very late half the prep time after', function () {
    $order = Order::factory()->make(['pickup_at' => now()->addMinutes(5), 'prep_minutes' => 5]);

    expect($order->lateAt()->equalTo(now()->addMinutes(5)))->toBeTrue()
        ->and($order->overdueAt()->equalTo(now()->addMinutes(5)->addSeconds(150)))->toBeTrue()
        // The pickup time itself is untouched.
        ->and($order->pickup_at->equalTo(now()->addMinutes(5)))->toBeTrue();
});

test('a new order starts with no recalls', function () {
    $order = Order::factory()->create();

    expect($order->recalled_count)->toBe(0)
        ->and($order->fresh()->recalled_count)->toBe(0);
});
