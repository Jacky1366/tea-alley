<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

const STATUS_PAGE = 'pages::orders.show';

/**
 * Each progress step's state ("done", "current", "todo"), by step.
 *
 * @return array<string, string>
 */
function progressSteps(string $html): array
{
    preg_match_all('/data-step="(\w+)"\s+data-state="(\w+)"/', $html, $m);

    return array_combine($m[1], $m[2]);
}

/**
 * The status page for $order, as its owner sees it.
 */
function statusPage(Order $order): string
{
    return test()->actingAs($order->user)->get(route('orders.show', $order))->assertOk()->getContent();
}

describe('progress bar', function () {
    test('shows each step of a paid order\'s progress', function (string $state, array $steps) {
        $order = Order::factory()->{$state}()->create();
        $html = statusPage($order);

        expect(progressSteps($html))->toBe($steps)
            ->and($html)->not->toContain('data-test="status-message"');
    })->with([
        'paid' => ['paid', ['paid' => 'current', 'preparing' => 'todo', 'ready' => 'todo', 'picked_up' => 'todo']],
        'preparing' => ['preparing', ['paid' => 'done', 'preparing' => 'current', 'ready' => 'todo', 'picked_up' => 'todo']],
        'ready' => ['ready', ['paid' => 'done', 'preparing' => 'done', 'ready' => 'current', 'picked_up' => 'todo']],
        'picked up' => ['pickedUp', ['paid' => 'done', 'preparing' => 'done', 'ready' => 'done', 'picked_up' => 'current']],
    ]);

    test('the first step says Received and the steps are in order', function () {
        $this->actingAs(($order = Order::factory()->paid()->create())->user)
            ->get(route('orders.show', $order))
            ->assertSeeInOrder(['Received', 'Preparing', 'Ready', 'Picked up']);
    });

    test('a recalled order goes back to Preparing', function () {
        $order = Order::factory()->ready()->create();
        $order->transitionTo(OrderStatus::Preparing);

        expect(progressSteps(statusPage($order)))
            ->toBe(['paid' => 'done', 'preparing' => 'current', 'ready' => 'todo', 'picked_up' => 'todo']);
    });

    test('a pending order says it is waiting for payment, with no progress bar', function () {
        $html = statusPage(Order::factory()->create(['status' => OrderStatus::PendingPayment]));

        expect($html)->toContain('data-test="status-message"')
            ->and(progressSteps($html))->toBe([])
            ->and($html)->toMatch('/data-test="status-message"[^>]*>\s*Payment pending/');
    });

    test('a cancelled order says it was cancelled, with no progress bar', function () {
        $html = statusPage(Order::factory()->cancelled()->create());

        expect(progressSteps($html))->toBe([])
            ->and($html)->toMatch('/data-test="status-message"[^>]*>\s*Cancelled/');
    });

    test('is in Traditional Chinese when the locale is zh_TW', function () {
        $order = Order::factory()->paid()->create();
        $order->user->forceFill(['locale' => 'zh_TW'])->save();

        $this->actingAs($order->user)->get(route('orders.show', $order))
            ->assertSee('訂單進度')
            ->assertSeeInOrder(['已收單', '製作中', '可取餐', '已取餐']);
    });
});

describe('live updates', function () {
    test('listens on the order\'s own private channel while the order can still change', function (string $state) {
        $order = Order::factory()->{$state}()->create();

        expect(statusPage($order))->toContain('data-channel="private-orders.'.$order->id.'"');
    })->with(['paid', 'preparing', 'ready']);

    test('a pending order is listened to as well: it can still be paid', function () {
        $order = Order::factory()->create(['status' => OrderStatus::PendingPayment]);

        expect(statusPage($order))->toContain('data-test="realtime-status"');
    });

    test('no indicator or polling once the order is picked up or cancelled', function (string $state) {
        expect(statusPage(Order::factory()->{$state}()->create()))->not->toContain('data-test="realtime-status"');
    })->with(['pickedUp', 'cancelled']);

    test('an OrderStatusChanged event on the order\'s channel redraws the status from the database', function () {
        $order = Order::factory()->preparing()->create();
        $page = Livewire::actingAs($order->user)->test(STATUS_PAGE, ['order' => $order]);
        expect(progressSteps($page->html())['preparing'])->toBe('current');

        Order::query()->findOrFail($order->id)->transitionTo(OrderStatus::Ready);
        $page->dispatch('echo-private:orders.'.$order->id.',OrderStatusChanged', ['order_id' => $order->id, 'status' => 'ready']);

        expect(progressSteps($page->html())['ready'])->toBe('current');
    });

    test('redrawing after an event still never queries the menu tables', function () {
        $order = Order::factory()->preparing()->create();
        OrderItem::factory()->for($order)->create();
        $page = Livewire::actingAs($order->user)->test(STATUS_PAGE, ['order' => $order]);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $page->dispatch('echo-private:orders.'.$order->id.',OrderStatusChanged', ['order_id' => $order->id, 'status' => 'ready']);

        expect($queries)->not->toBe([])
            ->and(collect($queries)->filter(fn (string $sql) => preg_match('/\b(menu_items|modifier_options|modifier_groups|categories)\b/', $sql))->all())
            ->toBe([]);
    });
});
