<?php

use App\Enums\OrderStatus;
use App\Filament\Widgets\BestSellersChart;
use App\Filament\Widgets\PopularToppingsChart;
use App\Filament\Widgets\SalesStats;
use App\Filament\Widgets\SalesTrendChart;
use App\Filament\Widgets\SugarLevelsChart;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Services\SalesReport;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * T2-9 (#47). The clock is Wed Nov 18, 2026 12:00 in Vancouver. Every expected number
 * below is worked out by hand from salesFixture().
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'America/Vancouver'));
});

function dashboardTime(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'America/Vancouver');
}

/**
 * An order paid at $paidAt (Vancouver), with the given amounts and lines.
 *
 * @param  array{subtotal: int, discount?: int, total: int}  $amounts
 * @param  list<array{0: string, 1: int, 2?: array<string, list<string>>}>  $lines  [name, quantity, [group => options]]
 */
function salesOrder(OrderStatus $status, ?string $paidAt, array $amounts, array $lines = []): Order
{
    $order = Order::factory()->create([
        'status' => $status,
        'paid_at' => $paidAt === null ? null : dashboardTime($paidAt)->utc(),
        'subtotal_cents' => $amounts['subtotal'],
        'discount_cents' => $amounts['discount'] ?? 0,
        'gst_cents' => 0,
        'pst_cents' => 0,
        'total_cents' => $amounts['total'],
    ]);

    foreach ($lines as $line) {
        $item = OrderItem::factory()->for($order)->create(['name_en' => $line[0], 'quantity' => $line[1]]);

        foreach ($line[2] ?? [] as $group => $options) {
            foreach ($options as $option) {
                OrderItemModifier::factory()->for($item)->create(['group_name_en' => $group, 'name_en' => $option]);
            }
        }
    }

    return $order;
}

/**
 * Counted (paid or later, paid within 30 Vancouver days):
 *   A  paid       today 09:00             sales 10.00          total 10.50   Classic ×2 (50% sugar, Pearls)
 *   B  picked up  today 00:00:30          sales  6.00 - 1.00   total  5.25   Taro ×1 (0% sugar, Pearls + Pudding)
 *   C  ready      yesterday 23:59:59      sales  8.00          total  8.40   Classic ×1 (50% sugar)
 *   F  preparing  Nov 11 (7 days ago)     sales 20.00          total 21.00   Taro ×3 (100% sugar)
 * Not counted:
 *   D  pending (never paid)                       50.00                      Ghost ×9
 *   E  cancelled                                  70.00                      Ghost ×9
 *   G  picked up  Oct 19 (30 days ago, outside)   90.00                      Old ×9
 */
function salesFixture(): void
{
    salesOrder(OrderStatus::Paid, '2026-11-18 09:00:00', ['subtotal' => 1000, 'total' => 1050],
        [['Classic Milk Tea', 2, ['Sugar' => ['50% Sugar'], 'Toppings' => ['Pearls']]]]);
    salesOrder(OrderStatus::PickedUp, '2026-11-18 00:00:30', ['subtotal' => 600, 'discount' => 100, 'total' => 525],
        [['Taro Slush', 1, ['Sugar' => ['0% Sugar'], 'Toppings' => ['Pearls', 'Pudding']]]]);
    salesOrder(OrderStatus::Ready, '2026-11-17 23:59:59', ['subtotal' => 800, 'total' => 840],
        [['Classic Milk Tea', 1, ['Sugar' => ['50% Sugar']]]]);
    salesOrder(OrderStatus::Preparing, '2026-11-11 15:00:00', ['subtotal' => 2000, 'total' => 2100],
        [['Taro Slush', 3, ['Sugar' => ['100% Sugar']]]]);

    salesOrder(OrderStatus::PendingPayment, null, ['subtotal' => 5000, 'total' => 5250],
        [['Ghost Tea', 9, ['Sugar' => ['0% Sugar'], 'Toppings' => ['Jelly']]]]);
    salesOrder(OrderStatus::Cancelled, null, ['subtotal' => 7000, 'total' => 7350],
        [['Ghost Tea', 9, ['Sugar' => ['0% Sugar'], 'Toppings' => ['Jelly']]]]);
    salesOrder(OrderStatus::PickedUp, '2026-10-19 23:00:00', ['subtotal' => 9000, 'total' => 9450],
        [['Old Tea', 9, ['Sugar' => ['0% Sugar'], 'Toppings' => ['Jelly']]]]);
}

describe('the numbers', function () {
    test('today, the last 7 days, and the last 30 days, split at Vancouver midnight', function () {
        salesFixture();
        $report = app(SalesReport::class);

        expect($report->totals(SalesReport::dayStart()))->toBe(['orders' => 2, 'sales_cents' => 1500, 'collected_cents' => 1575])     // A + B
            ->and($report->totals(SalesReport::dayStart(6)))->toBe(['orders' => 3, 'sales_cents' => 2300, 'collected_cents' => 2415]) // + C
            ->and($report->totals(SalesReport::recentStart()))->toBe(['orders' => 4, 'sales_cents' => 4300, 'collected_cents' => 4515]); // + F
    });

    test('the window starts at Vancouver midnight, not UTC midnight', function () {
        expect(SalesReport::dayStart()->equalTo(dashboardTime('2026-11-18 00:00:00')))->toBeTrue()
            ->and(SalesReport::dayStart(6)->equalTo(dashboardTime('2026-11-12 00:00:00')))->toBeTrue()
            ->and(SalesReport::recentStart()->equalTo(dashboardTime('2026-10-20 00:00:00')))->toBeTrue();
    });

    test('daily sales for the last 7 days, every day present, oldest first', function () {
        salesFixture();

        expect(app(SalesReport::class)->dailySales(7))->toBe([
            '2026-11-12' => 0,
            '2026-11-13' => 0,
            '2026-11-14' => 0,
            '2026-11-15' => 0,
            '2026-11-16' => 0,
            '2026-11-17' => 800,   // C, one second before midnight
            '2026-11-18' => 1500,  // A + B (B 30 seconds after midnight)
        ]);
    });

    test('best sellers by drinks sold, under the snapshot name', function () {
        salesFixture();

        // Taro: B 1 + F 3 = 4. Classic: A 2 + C 1 = 3. Ghost and Old Tea aren't counted.
        expect(app(SalesReport::class)->bestSellers(SalesReport::recentStart()))
            ->toBe(['Taro Slush' => 4, 'Classic Milk Tea' => 3]);
    });

    test('sugar levels and toppings count drinks, so a line of 2 counts twice', function () {
        salesFixture();
        $report = app(SalesReport::class);

        expect($report->optionCounts('Sugar', SalesReport::recentStart()))
            ->toBe(['100% Sugar' => 3, '50% Sugar' => 3, '0% Sugar' => 1])  // F 3; A 2 + C 1; B 1
            ->and($report->optionCounts('Toppings', SalesReport::recentStart()))
            ->toBe(['Pearls' => 3, 'Pudding' => 1]);                           // A 2 + B 1; B 1
    });

    test('nothing sold gives zeros and empty lists', function () {
        $report = app(SalesReport::class);

        expect($report->totals(SalesReport::dayStart()))->toBe(['orders' => 0, 'sales_cents' => 0, 'collected_cents' => 0])
            ->and($report->bestSellers(SalesReport::recentStart()))->toBe([])
            ->and($report->optionCounts('Sugar', SalesReport::recentStart()))->toBe([])
            ->and(array_sum(app(SalesReport::class)->dailySales(7)))->toBe(0);
    });

    test('the report never reads the menu tables, so renaming or deleting a drink changes nothing', function () {
        salesFixture();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $report = app(SalesReport::class);
        $report->totals(SalesReport::recentStart());
        $report->dailySales(7);
        $report->bestSellers(SalesReport::recentStart());
        $report->optionCounts('Sugar', SalesReport::recentStart());

        expect(collect($queries)->filter(fn (string $sql) => preg_match('/\b(menu_items|modifier_options|modifier_groups|categories)\b/', $sql))->all())
            ->toBe([]);
    });
});

describe('the dashboard', function () {
    test('managers and admins see every widget with the fixture numbers', function (string $role) {
        salesFixture();
        $this->actingAs(userWithRole($role));

        $this->get('/admin')->assertOk()
            ->assertSeeLivewire(SalesStats::class)
            ->assertSeeLivewire(SalesTrendChart::class)
            ->assertSeeLivewire(BestSellersChart::class)
            ->assertSeeLivewire(SugarLevelsChart::class)
            ->assertSeeLivewire(PopularToppingsChart::class);

        Livewire::test(SalesStats::class)
            ->assertSeeInOrder(['Sales today', '$15.00', '2 orders · $15.75 collected with tax'])
            ->assertSeeInOrder(['Last 7 days', '$23.00', '3 orders · $24.15 collected with tax'])
            ->assertSeeInOrder(['Last 30 days', '$43.00', '4 orders · $45.15 collected with tax']);

        Livewire::test(BestSellersChart::class)->assertOk()->assertSee('Taro Slush');
        Livewire::test(PopularToppingsChart::class)->assertOk()->assertSee('Pearls');
        Livewire::test(SugarLevelsChart::class)->assertOk()->assertSee('100% Sugar');
        Livewire::test(SalesTrendChart::class)->assertOk()->assertSee('Wed Nov 18');
    })->with(['manager', 'admin']);

    test('the widgets refuse anyone who cannot manage the menu', function (string $role) {
        $this->actingAs(userWithRole($role));

        expect(SalesStats::canView())->toBeFalse()
            ->and(SalesTrendChart::canView())->toBeFalse()
            ->and(BestSellersChart::canView())->toBeFalse()
            ->and(SugarLevelsChart::canView())->toBeFalse()
            ->and(PopularToppingsChart::canView())->toBeFalse();

        $this->get('/admin')->assertForbidden();
    })->with(['barista', 'customer']);

    test('the dashboard\'s queries do not grow with the number of orders', function () {
        $this->actingAs(userWithRole('manager'));
        $queriesFor = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(SalesStats::class);
            Livewire::test(BestSellersChart::class);
            Livewire::test(SugarLevelsChart::class);
            Livewire::test(PopularToppingsChart::class);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        salesFixture();
        $queriesFor();  // warm-up: the first run also fills per-process caches (the role lookup)
        $before = $queriesFor();
        salesFixture();
        salesFixture();

        expect($queriesFor())->toBe($before);
    });
});

describe('the menu\'s Most popular sort', function () {
    test('drinks sold in the last 30 days come first, by drinks sold; the rest keep the admin order', function () {
        $first = MenuItem::factory()->create(['name_en' => 'Admin First', 'sort_order' => 1]);
        $second = MenuItem::factory()->create(['name_en' => 'Admin Second', 'sort_order' => 2]);
        $hit = MenuItem::factory()->create(['name_en' => 'Big Hit', 'sort_order' => 3]);
        $steady = MenuItem::factory()->create(['name_en' => 'Steady Seller', 'sort_order' => 4]);
        $stale = MenuItem::factory()->create(['name_en' => 'Stale Favourite', 'sort_order' => 5]);

        $sell = function (MenuItem $drink, int $quantity, OrderStatus $status, ?string $paidAt): void {
            $order = salesOrder($status, $paidAt, ['subtotal' => 100, 'total' => 105]);
            OrderItem::factory()->for($order)->forMenuItem($drink)->create(['quantity' => $quantity, 'name_en' => 'Renamed since']);
        };

        $sell($hit, 5, OrderStatus::PickedUp, '2026-11-18 08:00:00');
        $sell($steady, 2, OrderStatus::Paid, '2026-10-20 00:00:01');           // first second of the window
        $sell($stale, 50, OrderStatus::PickedUp, '2026-10-19 23:59:59');        // just outside it
        $sell($first, 50, OrderStatus::PendingPayment, null);                    // never paid
        $sell($second, 50, OrderStatus::Cancelled, null);

        $html = $this->get('/')->assertOk()->getContent();

        expect($html)->toMatch('/Big Hit.*Steady Seller.*Admin First.*Admin Second.*Stale Favourite/s');
    });
});
