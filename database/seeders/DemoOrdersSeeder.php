<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\PickupType;
use App\Models\MenuItem;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\StoreHour;
use App\Models\User;
use App\Services\CartContents;
use App\Services\ModifierValidator;
use App\Services\OrderingWindow;
use App\Services\OrderService;
use App\Services\ValidatedDrink;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use LogicException;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * T3-5: two weeks of believable order history for the demo (dashboard, best sellers, the
 * menu's "Most popular"). Run it again on demo day so the window ends today:
 *
 *     php artisan db:seed --class=DemoOrdersSeeder
 *
 * - Every order goes through OrderService::place() with the clock set to the order's time,
 *   so the options are checked by ModifierValidator, the amounts come from PricingService,
 *   the snapshot is exactly what checkout stores, and the ordering window (store_hours) is
 *   enforced. Nothing here calculates an amount.
 * - Orders are then moved to their final status with a plain UPDATE, never
 *   Order::transitionTo(): that would dispatch OrderStatusChanged, which broadcasts and sends
 *   the "ready" email (real Gmail in .env). Payment is as if by the fake gateway: no Stripe.
 * - Only the 20 demo customers' (DemoCustomersSeeder) orders are deleted and regenerated;
 *   nobody else's are touched. No migrate:fresh needed.
 * - Times: open hours only, lunch (12-2 PM) and afternoon (3-5 PM) peaks, busier weekends,
 *   and nothing in the future: today's orders are only those already picked up (or
 *   cancelled) by now, so the barista board stays empty for the live demo.
 * - A fixed random seed: the same "now" gives the same orders (order numbers aside, which
 *   OrderService draws at random).
 * - Local (and tests) only; anywhere else it stops with the reason.
 */
class DemoOrdersSeeder extends Seeder
{
    use WithoutModelEvents;

    public const DAYS = 14;

    public const RANDOM_SEED = 51_53;

    /** Idempotency keys of seeded orders start with this. */
    public const KEY_PREFIX = 'demo-seed-';

    /** Orders on an ordinary weekday, before the random ±20%. Weekends get 1.6×. */
    private const ORDERS_PER_WEEKDAY = 22;

    private const WEEKEND_FACTOR = 1.6;

    /** Share of orders never paid and cancelled by orders:cancel-stale. */
    private const CANCELLED_PERCENT = 5;

    /** How popular each seeded drink is (MenuSeeder's names). Others weigh 2. */
    private const DRINK_WEIGHTS = [
        'Brown Sugar Pearl Milk Tea' => 16, 'Classic Milk Tea' => 13, 'Taro Milk Tea' => 9,
        'Brown Sugar Fresh Milk' => 8, 'Passion Fruit Green Tea' => 7, 'Matcha Fresh Milk' => 6,
        'Osmanthus Oolong Milk Tea' => 6, 'Mango Green Tea' => 6, 'Grapefruit Jasmine Tea' => 5,
        'Strawberry Fresh Milk' => 4, 'Black Tea Fresh Milk' => 4, 'Winter Melon Lemonade' => 3,
        'Lemon Sparkling Green Tea' => 3, 'Peach Oolong Sparkling Tea' => 3, 'Lychee Sparkling Tea' => 2,
        'Jasmine Green Tea' => 3, 'Roasted Oolong Tea' => 2, 'Assam Black Tea' => 2,
        'Jasmine Milk Green Tea' => 7, 'Taro Purée Fresh Milk' => 6, 'Mixed Fruit Tea' => 5,
        'Hojicha Milk Tea' => 4, 'Kumquat Lemon Green Tea' => 4, 'Black Sesame Milk Tea' => 4,
        'Yuzu Green Tea' => 3, 'Tieguanyin Fresh Milk' => 3, 'Kyoho Grape Sparkling Tea' => 3,
        'Green Plum Sparkling Tea' => 2, 'Four Seasons Oolong Tea' => 2, 'Jin Xuan Oolong Tea' => 2,
    ];

    /** How often each option is chosen within its group (MenuSeeder's names). Others weigh 1. */
    private const OPTION_WEIGHTS = [
        'Medium' => 65, 'Large' => 35,
        '0% Sugar' => 10, '25% Sugar' => 18, '50% Sugar' => 34, '75% Sugar' => 20, '100% Sugar' => 18,
        'No Ice' => 10, 'Light Ice' => 14, 'Less Ice' => 30, 'Regular Ice' => 38, 'Extra Ice' => 8,
        'Pearls' => 50, 'Pudding' => 20, 'Coconut Jelly' => 18, 'Grass Jelly' => 12,
        'Oat Milk' => 70, 'Lactose-free Milk' => 30,
    ];

    /** How many options an optional group gets: count => weight. Others: 0 (70) or 1 (30). */
    private const OPTIONAL_COUNTS = [
        'Toppings' => [0 => 45, 1 => 40, 2 => 12, 3 => 3],
        'Milk' => [0 => 82, 1 => 18],
    ];

    private Randomizer $random;

    private int $sequence = 0;

    public function __construct(
        private readonly OrderService $orders,
        private readonly ModifierValidator $validator,
        private readonly OrderingWindow $window,
    ) {}

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(sprintf(
                'DemoOrdersSeeder only runs in the local environment (APP_ENV is "%s"). Seed while local, then switch to production.',
                app()->environment(),
            ));
        }

        $this->call(DemoCustomersSeeder::class);

        $customers = User::query()->whereIn('email', DemoCustomersSeeder::emails())->orderBy('email')->get();
        $deleted = Order::query()->whereIn('user_id', $customers->modelKeys())->delete();

        $now = CarbonImmutable::now();
        $menu = MenuItem::query()
            ->with(MenuItem::builderRelations())
            ->where('is_available', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (MenuItem $item): bool => $item->category->is_active)
            ->values();

        if ($menu->isEmpty() || $customers->isEmpty()) {
            $this->command?->warn('DemoOrdersSeeder: no available drinks or no demo customers; nothing seeded.');

            return;
        }

        $this->random = new Randomizer(new Mt19937(self::RANDOM_SEED));
        $this->sequence = 0;
        $created = 0;
        // Restored afterwards: the clock may already be frozen by the caller (a test's travelTo).
        $frozenClock = Date::getTestNow();

        try {
            foreach (range(self::DAYS - 1, 0) as $daysAgo) {
                foreach ($this->orderTimes($now, $daysAgo) as [$placedAt, $plan]) {
                    if ($this->placeOne($placedAt, $plan, $customers, $menu)) {
                        $created++;
                    }
                }
            }
        } finally {
            Date::setTestNow($frozenClock); // the clock as it was, whatever happened
        }

        $this->command?->info("DemoOrdersSeeder: removed {$deleted} old demo orders, created {$created} for the last ".self::DAYS.' days.');
    }

    /**
     * The orders for one Vancouver day, oldest first: [placed at (UTC), lifecycle plan].
     * Only times inside the ordering window, and only orders whose whole lifecycle is over by $now.
     *
     * @return list<array{0: CarbonImmutable, 1: array<string, int|null>}>
     */
    private function orderTimes(CarbonImmutable $now, int $daysAgo): array
    {
        $timezone = config()->string('app.display_timezone');
        $day = $now->setTimezone($timezone)->startOfDay()->subDays($daysAgo);
        $hours = StoreHour::query()->where('day_of_week', $day->dayOfWeek)->first();

        // Drawn even on closed days, so one day's hours don't shift every later day's orders.
        $count = intval(round(
            self::ORDERS_PER_WEEKDAY
            * ($day->isWeekend() ? self::WEEKEND_FACTOR : 1)
            * $this->random->getFloat(0.8, 1.2),
        ));

        if ($hours === null || $hours->is_closed || $hours->opens_at === null || $hours->closes_at === null) {
            return [];
        }

        // 15-minute buckets across the ordering window, weighted for the lunch and afternoon peaks.
        $opens = $day->setTimeFromTimeString($hours->opens_at);
        $ends = $day->setTimeFromTimeString($hours->closes_at)->subMinutes($hours->ordering_cutoff_minutes);
        $buckets = [];

        for ($start = $opens; $start < $ends; $start = $start->addMinutes(15)) {
            $hour = $start->hour;
            $buckets[] = [$start, match (true) {
                $hour >= 12 && $hour < 14 => 5,
                $hour >= 15 && $hour < 17 => 4,
                default => 1,
            }];
        }

        $orders = [];

        for ($i = 0; $i < $count; $i++) {
            $bucket = $this->weighted(array_column($buckets, 1, null), array_keys($buckets));
            $placedAt = $buckets[$bucket][0]->addSeconds($this->random->getInt(0, 15 * 60 - 1));
            $plan = $this->lifecyclePlan();

            if ($placedAt >= $ends) {
                continue;
            }

            // Nothing in the future, and today only orders that are already finished.
            $finishedAt = $placedAt->addSeconds(array_sum(array_filter($plan, fn ($s) => $s !== null)));

            if ($finishedAt->greaterThan($now) || ! $this->window->isOpen($placedAt)) {
                continue;
            }

            $orders[] = [$placedAt->utc(), $plan];
        }

        usort($orders, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $orders;
    }

    /**
     * Seconds between the steps: cancelled orders only have "cancelled" (orders:cancel-stale
     * after 30 minutes); the rest paid -> started -> ready -> picked up.
     *
     * @return array<string, int|null>
     */
    private function lifecyclePlan(): array
    {
        if ($this->random->getInt(1, 100) <= self::CANCELLED_PERCENT) {
            return ['cancelled' => $this->random->getInt(31, 40) * 60, 'paid' => null, 'started' => null, 'ready' => null, 'picked_up' => null];
        }

        return [
            'cancelled' => null,
            'paid' => $this->random->getInt(15, 90),            // the customer pays
            'started' => $this->random->getInt(60, 8 * 60),     // in the queue
            'ready' => $this->random->getInt(3 * 60, 6 * 60),   // made (prep is 3 minutes)
            'picked_up' => $this->random->getInt(60, 12 * 60),  // collected
        ];
    }

    /**
     * Places one order at $placedAt through OrderService, then writes its final status.
     *
     * @param  array<string, int|null>  $plan
     * @param  Collection<int, User>  $customers
     * @param  Collection<int, MenuItem>  $menu
     */
    private function placeOne(CarbonImmutable $placedAt, array $plan, Collection $customers, Collection $menu): bool
    {
        $customer = $customers[$this->weighted(
            array_map(fn (int $i): int => $i < 5 ? 3 : 1, array_keys($customers->all())), // a few regulars
            array_keys($customers->all()),
        )];

        $drinks = $this->drinks($menu);

        if ($drinks === []) {
            return false;
        }

        Date::setTestNow($placedAt);

        $order = $this->orders->place(
            $customer,
            new CartContents($drinks, []),
            PickupType::Asap,
            null,
            self::KEY_PREFIX.(++$this->sequence),
        );

        $this->finish($order, $placedAt, $plan);

        return true;
    }

    /**
     * 1 to 4 drinks in 1 to 3 lines, each checked by ModifierValidator. A line that doesn't
     * validate (an option sold out in this database, say) is left out, never stored.
     *
     * @param  Collection<int, MenuItem>  $menu
     * @return list<ValidatedDrink>
     */
    private function drinks(Collection $menu): array
    {
        $cups = $this->weighted([45, 30, 15, 10], [1, 2, 3, 4]);
        $drinks = [];

        while ($cups > 0 && count($drinks) < 3) {
            $quantity = $cups > 1 && $this->random->getInt(1, 100) <= 25 ? 2 : 1;
            $cups -= $quantity;

            $item = $menu[$this->weighted(
                $menu->map(fn (MenuItem $item): int => self::DRINK_WEIGHTS[$item->name_en] ?? 2)->all(),
                $menu->keys()->all(),
            )];

            try {
                $drinks[] = $this->validator->validate($item, $this->options($item), $quantity);
            } catch (ValidationException) {
                continue;
            }
        }

        return $drinks;
    }

    /**
     * Option ids for every group the drink has: one for a single-choice required group,
     * a weighted count for optional ones, available options only.
     *
     * @return list<int>
     */
    private function options(MenuItem $item): array
    {
        $ids = [];

        foreach ($item->modifierGroups as $group) {
            $options = $group->options->where('is_available', true)->values();
            $counts = self::OPTIONAL_COUNTS[$group->name_en] ?? [0 => 70, 1 => 30];  // count => weight

            $count = $group->effectiveMin() > 0
                ? max(1, $group->effectiveMin())
                : $this->weighted($counts, array_keys($counts));

            $count = min($count, $group->max_select, $options->count());

            for ($picked = 0; $picked < $count; $picked++) {
                $index = $this->weighted(
                    $options->map(fn (ModifierOption $option): int => self::OPTION_WEIGHTS[$option->name_en] ?? 1)->all(),
                    $options->keys()->all(),
                );
                $ids[] = $options[$index]->id;
                $options = $options->forget($index);
            }
        }

        return $ids;
    }

    /**
     * Moves the new pending order to its final status with one UPDATE: no model events and no
     * OrderStatusChanged, so nothing is broadcast or emailed.
     *
     * @param  array<string, int|null>  $plan
     */
    private function finish(Order $order, CarbonImmutable $placedAt, array $plan): void
    {
        if ($plan['cancelled'] !== null) {
            $cancelledAt = $placedAt->addSeconds($plan['cancelled']);

            Order::query()->whereKey($order->id)->update([
                'status' => OrderStatus::Cancelled->value,
                'cancelled_at' => $cancelledAt,
                'updated_at' => $cancelledAt,
            ]);

            return;
        }

        $paidAt = $placedAt->addSeconds($plan['paid'] ?? 0);
        $startedAt = $paidAt->addSeconds($plan['started'] ?? 0);
        $readyAt = $startedAt->addSeconds($plan['ready'] ?? 0);
        $pickedUpAt = $readyAt->addSeconds($plan['picked_up'] ?? 0);

        Order::query()->whereKey($order->id)->update([
            'status' => OrderStatus::PickedUp->value,
            'paid_at' => $paidAt,
            'started_at' => $startedAt,
            'ready_at' => $readyAt,
            'picked_up_at' => $pickedUpAt,
            'updated_at' => $pickedUpAt,
        ]);
    }

    /**
     * One of $values, chosen with the matching $weights.
     *
     * @template T
     *
     * @param  array<array-key, int>  $weights
     * @param  array<array-key, T>  $values
     * @return T
     */
    private function weighted(array $weights, array $values): mixed
    {
        $weights = array_values($weights);
        $values = array_values($values);

        if ($values === [] || count($values) !== count($weights)) {
            throw new LogicException('weighted() needs one weight per value, and at least one value.');
        }

        $roll = $this->random->getInt(1, max(1, array_sum($weights)));

        foreach ($weights as $i => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return $values[$i];
            }
        }

        return $values[count($values) - 1];
    }
}
