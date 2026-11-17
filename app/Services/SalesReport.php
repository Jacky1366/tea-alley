<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sales figures for the manager dashboard and the menu's "Most popular" sort (T2-9).
 *
 * Read-only reporting: it adds up amounts PricingService already stored on each order and
 * calculates nothing that is stored or charged. Every figure:
 *  - reads the order snapshots only (orders, order_items, order_item_modifiers), never the
 *    menu tables, so renamed or deleted drinks keep their history;
 *  - counts paid-or-later orders only (OrderStatus::paidOrLater): pending and cancelled
 *    orders were never paid;
 *  - places an order on the Vancouver day it was paid (paid_at).
 *
 * "Sales" is net of discount and before tax (subtotal - discount): GST and PST are collected
 * for the government. The amount collected including tax is reported next to it.
 */
final class SalesReport
{
    /** The drink builder's group names, as snapshotted on order_item_modifiers. */
    public const SUGAR_GROUP = 'Sugar';

    public const TOPPINGS_GROUP = 'Toppings';

    /** The window for best sellers, option counts, and the menu's popularity sort: today and the 29 days before. */
    public const RECENT_DAYS = 30;

    /**
     * The UTC instant the Vancouver day $daysAgo days before today starts (0 = today).
     */
    public static function dayStart(int $daysAgo = 0): CarbonImmutable
    {
        return CarbonImmutable::now(config()->string('app.display_timezone'))
            ->startOfDay()
            ->subDays($daysAgo)
            ->utc();
    }

    /** The start of the RECENT_DAYS window. */
    public static function recentStart(): CarbonImmutable
    {
        return self::dayStart(self::RECENT_DAYS - 1);
    }

    /**
     * Narrows an order_items query to lines of orders paid (or later) since $from.
     *
     * @param  Builder<OrderItem>  $lines
     * @return Builder<OrderItem>
     */
    public static function soldSince(Builder $lines, CarbonImmutable $from): Builder
    {
        return $lines->whereHas('order', fn (Builder $orders) => self::paidSince($orders, $from));
    }

    /**
     * Order count, sales (subtotal - discount), and total collected (with tax) for orders
     * paid in [$from, $to).
     *
     * @return array{orders: int, sales_cents: int, collected_cents: int}
     */
    public function totals(CarbonImmutable $from, ?CarbonImmutable $to = null): array
    {
        $row = self::paidSince(Order::query(), $from, $to)
            ->toBase()
            ->selectRaw('COUNT(*) AS orders, SUM(subtotal_cents - discount_cents) AS sales_cents, SUM(total_cents) AS collected_cents')
            ->first();

        // MySQL returns SUM() as a decimal string, and NULL when there are no rows.
        return [
            'orders' => intval($row->orders ?? 0),
            'sales_cents' => intval($row->sales_cents ?? 0),
            'collected_cents' => intval($row->collected_cents ?? 0),
        ];
    }

    /**
     * Sales per Vancouver day for the last $days days, oldest first, every day present.
     * Grouped in PHP, not SQL, so the day boundaries come from the timezone database on
     * both SQLite and MySQL.
     *
     * @return array<string, int> Y-m-d (Vancouver) => sales cents
     */
    public function dailySales(int $days = 7): array
    {
        $timezone = config()->string('app.display_timezone');
        $start = self::dayStart($days - 1);

        $byDay = [];
        for ($day = $start->setTimezone($timezone); count($byDay) < $days; $day = $day->addDay()) {
            $byDay[$day->toDateString()] = 0;
        }

        self::paidSince(Order::query(), $start)
            ->select(['id', 'paid_at', 'subtotal_cents', 'discount_cents'])
            ->orderBy('id')
            ->each(function (Order $order) use (&$byDay, $timezone): void {
                $day = $order->paid_at?->setTimezone($timezone)->toDateString();

                if ($day !== null && isset($byDay[$day])) {
                    $byDay[$day] += $order->subtotal_cents - $order->discount_cents;
                }
            });

        return $byDay;
    }

    /**
     * The most sold drinks since $from, by quantity, under their snapshot name.
     *
     * @return array<string, int> name => drinks sold, most first
     */
    public function bestSellers(CarbonImmutable $from, int $limit = 5): array
    {
        return self::soldSince(OrderItem::query(), $from)
            ->toBase()
            ->selectRaw('name_en, SUM(quantity) AS sold')
            ->groupBy('name_en')
            ->orderByDesc('sold')
            ->orderBy('name_en')
            ->limit($limit)
            ->pluck('sold', 'name_en')
            ->map(fn (mixed $sold): int => intval($sold))
            ->all();
    }

    /**
     * How many drinks sold since $from had each option of the snapshot group $group
     * (e.g. "Sugar"). A line of 2 drinks with Pearls counts 2 Pearls.
     *
     * @return array<string, int> option name => drinks, most first
     */
    public function optionCounts(string $group, CarbonImmutable $from): array
    {
        return OrderItemModifier::query()
            ->join('order_items', 'order_items.id', '=', 'order_item_modifiers.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_item_modifiers.group_name_en', $group)
            ->whereIn('orders.status', OrderStatus::paidOrLater())
            ->where('orders.paid_at', '>=', $from)
            ->toBase()
            ->selectRaw('order_item_modifiers.name_en AS name, SUM(order_items.quantity) AS drinks')
            ->groupBy('order_item_modifiers.name_en')
            ->orderByDesc('drinks')
            ->orderBy('name')
            ->pluck('drinks', 'name')
            ->map(fn (mixed $drinks): int => intval($drinks))
            ->all();
    }

    /**
     * @param  Builder<Order>  $orders
     * @return Builder<Order>
     */
    private static function paidSince(Builder $orders, CarbonImmutable $from, ?CarbonImmutable $to = null): Builder
    {
        return $orders
            ->whereIn('status', OrderStatus::paidOrLater())
            ->where('paid_at', '>=', $from)
            ->when($to !== null, fn (Builder $query) => $query->where('paid_at', '<', $to));
    }
}
