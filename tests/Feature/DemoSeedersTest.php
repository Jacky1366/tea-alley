<?php

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreHour;
use App\Models\User;
use App\Services\ModifierValidator;
use App\Services\OrderingWindow;
use App\Services\PricingService;
use App\Services\SalesReport;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoCustomersSeeder;
use Database\Seeders\DemoOrdersSeeder;
use Database\Seeders\DemoUserSeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\StoreHoursSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/*
 * T3-5 (#53). The clock is Wed Nov 18, 2026 16:30 in Vancouver: mid-afternoon, so "today"
 * already has finished orders, and the 14 days cover two weekends.
 */
beforeEach(function () {
    $this->seed([RoleSeeder::class, MenuSeeder::class, StoreHoursSeeder::class]);
    $this->travelTo(CarbonImmutable::parse('2026-11-18 16:30:00', 'America/Vancouver'));
});

/**
 * @return Collection<int, Order>
 */
function demoOrders(): Collection
{
    return Order::query()
        ->where('idempotency_key', 'like', DemoOrdersSeeder::KEY_PREFIX.'%')
        ->with(['items' => fn ($q) => $q->orderBy('id'), 'items.modifiers' => fn ($q) => $q->orderBy('id')])
        ->orderBy('id')
        ->get();
}

function seedDemoOrders(): void
{
    test()->seed(DemoOrdersSeeder::class);
}

describe('demo customers', function () {
    test('20 verified customers, half English and half Chinese, password "password"', function () {
        $this->seed(DemoCustomersSeeder::class);

        $customers = User::query()->whereIn('email', DemoCustomersSeeder::emails())->orderBy('email')->get();

        expect($customers)->toHaveCount(20)
            ->and($customers->first()->email)->toBe('customer01@tea-alley.test')
            ->and($customers->last()->email)->toBe('customer20@tea-alley.test')
            ->and($customers->every(fn (User $u) => $u->hasVerifiedEmail() && $u->hasRole('customer') && $u->getRoleNames()->count() === 1))->toBeTrue()
            ->and($customers->countBy('locale')->all())->toBe(['en' => 10, 'zh_TW' => 10])
            ->and(Hash::check('password', $customers->first()->password))->toBeTrue();
    });

    test('running it again changes nothing', function () {
        $this->seed(DemoCustomersSeeder::class);
        $this->seed(DemoCustomersSeeder::class);

        expect(User::query()->whereIn('email', DemoCustomersSeeder::emails())->count())->toBe(20);
    });

    test('never runs in production', function () {
        app()->detectEnvironment(fn () => 'production');

        // Directly, not through db:seed (which first asks to confirm in production).
        app(DemoCustomersSeeder::class)->setContainer(app())->__invoke();

        expect(User::query()->whereIn('email', DemoCustomersSeeder::emails())->count())->toBe(0);
    });
});

describe('demo orders', function () {
    test('every total is exactly what PricingService gives for the order\'s lines', function () {
        seedDemoOrders();
        $orders = demoOrders();
        $validator = app(ModifierValidator::class);
        $pricing = app(PricingService::class);
        $menu = MenuItem::query()->with(MenuItem::builderRelations())->get()->keyBy('id');

        expect($orders->count())->toBeGreaterThan(200);

        foreach ($orders as $order) {
            $drinks = $order->items->map(fn (OrderItem $line) => $validator->validate(
                $menu[$line->menu_item_id],
                $line->modifiers->pluck('modifier_option_id')->all(),
                $line->quantity,
            ))->all();
            $priced = $pricing->price($drinks);

            expect([$order->subtotal_cents, $order->discount_cents, $order->gst_cents, $order->pst_cents, $order->total_cents])
                ->toBe([$priced->subtotal_cents, $priced->discount_cents, $priced->gst_cents, $priced->pst_cents, $priced->total_cents]);

            // The snapshot matches what checkout stores: name and unit price per line, options per line.
            foreach ($order->items as $i => $line) {
                expect($line->unit_price_cents)->toBe($priced->lines[$i]->unit_price_cents)
                    ->and($line->name_en)->toBe($menu[$line->menu_item_id]->name_en)
                    ->and($line->modifiers->pluck('modifier_option_id')->sort()->values()->all())
                    ->toBe(collect($priced->lines[$i]->drink->optionIds)->sort()->values()->all());
            }

            expect($order->items->sum('quantity'))->toBeBetween(1, 4)
                ->and($order->pickup_type->value)->toBe('asap')
                ->and($order->prep_minutes)->toBe(3)
                ->and($order->pickup_at->equalTo($order->created_at->addMinutes(3)))->toBeTrue()
                ->and($order->stripe_session_id)->toBeNull();
        }
    });

    test('nothing is emailed, notified, or broadcast', function () {
        Mail::fake();
        Notification::fake();
        Event::fake([OrderStatusChanged::class]);

        seedDemoOrders();

        expect(demoOrders()->count())->toBeGreaterThan(200);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
        Event::assertNotDispatched(OrderStatusChanged::class);
    });

    test('running it again replaces the demo orders and leaves everyone else\'s alone', function () {
        $other = Order::factory()->pickedUp()->create(['total_cents' => 1234]);
        $demoCustomerOwnOrder = null;

        seedDemoOrders();
        $first = demoOrders()->count();

        // A real order a demo customer placed by hand during the demo also goes (it's theirs).
        $customer = User::query()->where('email', 'customer01@tea-alley.test')->firstOrFail();
        $demoCustomerOwnOrder = Order::factory()->for($customer)->paid()->create();

        seedDemoOrders();

        expect(demoOrders()->count())->toBe($first)
            ->and(Order::query()->count())->toBe($first + 1)
            ->and(Order::query()->find($other->id)?->total_cents)->toBe(1234)
            ->and(Order::query()->find($demoCustomerOwnOrder->id))->toBeNull();
    });

    test('the same day and time give the same orders (a fixed random seed)', function () {
        $signature = fn () => demoOrders()->map(fn (Order $o) => [
            $o->created_at->toIso8601String(),
            $o->status->value,
            $o->total_cents,
            $o->items->map(fn (OrderItem $line) => $line->name_en.' x'.$line->quantity.' '.$line->modifiers->pluck('name_en')->join('/'))->all(),
        ])->all();

        seedDemoOrders();
        $first = $signature();
        seedDemoOrders();

        expect($signature())->toBe($first);
    });

    test('refuses to run in production, and says why', function () {
        app()->detectEnvironment(fn () => 'production');

        // Directly, not through db:seed (which first asks to confirm in production).
        expect(fn () => app(DemoOrdersSeeder::class)->setContainer(app())->__invoke())
            ->toThrow(RuntimeException::class, 'only runs in the local environment (APP_ENV is "production")');

        expect(Order::count())->toBe(0);
    });
});

describe('when the demo orders happen', function () {
    test('only inside the ordering window, never in the future, with steps in order', function () {
        seedDemoOrders();
        $window = app(OrderingWindow::class);
        $now = now();

        foreach (demoOrders() as $order) {
            expect($window->isOpen($order->created_at))->toBeTrue();

            $steps = $order->status === OrderStatus::Cancelled
                ? [$order->created_at, $order->cancelled_at]
                : [$order->created_at, $order->paid_at, $order->started_at, $order->ready_at, $order->picked_up_at];

            foreach ($steps as $i => $time) {
                expect($time)->not->toBeNull()
                    ->and($time->lessThanOrEqualTo($now))->toBeTrue();

                if ($i > 0) {
                    expect($time->greaterThanOrEqualTo($steps[$i - 1]))->toBeTrue();
                }
            }
        }

        expect(demoOrders()->map(fn (Order $o) => $o->status)->unique()->sort()->values()->all())
            ->toEqualCanonicalizing([OrderStatus::Cancelled, OrderStatus::PickedUp])
            ->and(demoOrders()->where('status', OrderStatus::Cancelled)->count())->toBeLessThan(demoOrders()->count() / 10)
            ->and(demoOrders()->where('status', OrderStatus::Cancelled)->every(fn (Order $o) => $o->paid_at === null))->toBeTrue();
    });

    test('a closed day has no orders', function () {
        StoreHour::query()->where('day_of_week', CarbonImmutable::MONDAY)->update(['is_closed' => true]);

        seedDemoOrders();

        expect(demoOrders()->filter(fn (Order $o) => $o->created_at->setTimezone('America/Vancouver')->isMonday()))->toBeEmpty()
            ->and(demoOrders())->not->toBeEmpty();
    });

    test('lunch and afternoon are the peaks, and weekends are busier', function () {
        seedDemoOrders();
        $local = demoOrders()->map(fn (Order $o) => $o->created_at->setTimezone('America/Vancouver'));

        $peak = $local->filter(fn ($t) => ($t->hour >= 12 && $t->hour < 14) || ($t->hour >= 15 && $t->hour < 17))->count() / 4;  // per hour
        $offPeak = $local->reject(fn ($t) => ($t->hour >= 12 && $t->hour < 14) || ($t->hour >= 15 && $t->hour < 17))->count() / 6; // 11-12, 14-15, 17-21

        $perDay = $local->filter(fn ($t) => ! $t->isToday())->countBy(fn ($t) => $t->toDateString());
        $weekend = $perDay->filter(fn ($n, $day) => CarbonImmutable::parse($day)->isWeekend())->avg();
        $weekday = $perDay->reject(fn ($n, $day) => CarbonImmutable::parse($day)->isWeekend())->avg();

        expect($peak)->toBeGreaterThan(2 * $offPeak)
            ->and($weekend)->toBeGreaterThan(1.3 * $weekday);
    });
});

describe('what the demo shows', function () {
    test('every dashboard figure has data', function () {
        seedDemoOrders();
        $report = app(SalesReport::class);

        expect($report->totals(SalesReport::dayStart())['orders'])->toBeGreaterThan(0)          // today, before 4:30 PM
            ->and($report->totals(SalesReport::dayStart(6))['orders'])->toBeGreaterThan(50)
            ->and($report->totals(SalesReport::recentStart())['orders'])->toBeGreaterThan(200)
            ->and(array_filter($report->dailySales(7)))->toHaveCount(7)                          // a bar for every day
            ->and($report->bestSellers(SalesReport::recentStart()))->toHaveCount(5)
            ->and(array_key_first($report->bestSellers(SalesReport::recentStart())))->toContain('Milk Tea')
            ->and(array_key_first($report->optionCounts('Sugar', SalesReport::recentStart())))->toBe('50% Sugar')
            ->and(array_key_first($report->optionCounts('Toppings', SalesReport::recentStart())))->toBe('Pearls');
    });

    test('the menu\'s Most popular order follows the seeded sales', function () {
        seedDemoOrders();

        $sold = OrderItem::query()
            ->whereHas('order', fn ($q) => $q->whereIn('status', OrderStatus::paidOrLater()))
            ->selectRaw('menu_item_id, SUM(quantity) AS sold')
            ->groupBy('menu_item_id')
            ->pluck('sold', 'menu_item_id');

        $expected = MenuItem::query()->orderBy('sort_order')->orderBy('id')->get()
            ->sortByDesc(fn (MenuItem $item) => intval($sold[$item->id] ?? 0))
            ->pluck('name_en')
            ->values()
            ->all();

        $html = $this->get('/')->assertOk()->getContent();
        $positions = collect($expected)->mapWithKeys(fn (string $name) => [$name => strpos($html, e($name))]);

        expect($positions->filter(fn ($pos) => $pos === false))->toBeEmpty()
            ->and($positions->sort()->keys()->all())->toBe($expected);
    });

    test('a demo customer sees their order history', function () {
        seedDemoOrders();
        $customer = User::query()->where('email', 'customer01@tea-alley.test')->firstOrFail();
        $latest = Order::query()->where('user_id', $customer->id)->latest('created_at')->firstOrFail();

        $this->actingAs($customer)->get(route('orders.index'))->assertOk()->assertSee($latest->order_number);
    });
});

describe('in production with TEA_ALLEY_DEMO_DATA=true (the Laravel Cloud demo, #67)', function () {
    beforeEach(function () {
        app()->detectEnvironment(fn () => 'production');
        config(['tea-alley.demo_data' => true]);
    });

    test('the opt-in is off by default', function () {
        expect((require config_path('tea-alley.php'))['demo_data'])->toBeFalse();
    });

    test('the demo customers are created, with password "password", as customers only', function () {
        app(DemoCustomersSeeder::class)->setContainer(app())->__invoke();

        $customers = User::query()->whereIn('email', DemoCustomersSeeder::emails())->get();

        expect($customers)->toHaveCount(20)
            ->and($customers->every(fn (User $u) => $u->getRoleNames()->all() === ['customer']))->toBeTrue()
            ->and(Hash::check('password', $customers->first()->password))->toBeTrue();
    });

    test('the demo orders are created, with no emails and no broadcasts', function () {
        Notification::fake();
        Event::fake([OrderStatusChanged::class]);

        app(DemoOrdersSeeder::class)->setContainer(app())->__invoke();

        expect(demoOrders())->not->toBeEmpty();
        Notification::assertNothingSent();
        Event::assertNotDispatched(OrderStatusChanged::class);
    });

    test('staff demo accounts are still never seeded', function () {
        app(DemoUserSeeder::class)->setContainer(app())->__invoke();

        expect(User::query()->where('email', 'like', '%@tea-alley.test')->count())->toBe(0);
    });
});
