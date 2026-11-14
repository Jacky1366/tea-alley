<?php

use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Enums\PickupType;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\User;
use App\Services\Cart;
use App\Services\CartContents;
use App\Services\ModifierValidator;
use App\Services\OrderNumberGenerator;
use App\Services\OrderService;
use App\Services\PricingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
 * Fixture: "Order Tea" ($6.00, prep 5) with
 *   Size      required single-select: Medium (+0), Large +50
 *   Toppings  optional, up to 3: Pearls +75, Pudding +100
 * and "Order Soda" ($5.00, carbonated, prep 2, no options).
 * Ordering is open 11:00-22:00 (cutoff 15) every day; the clock is Wed Nov 18, 2026
 * 12:00 in Vancouver.
 */
beforeEach(function () {
    setStoreHours(array_fill(0, 7, ['11:00:00', '22:00:00']));
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'America/Vancouver'));

    $this->tea = MenuItem::factory()->create(['name_en' => 'Order Tea', 'name_zh' => '訂單茶', 'price_cents' => 600, 'prep_minutes' => 5]);
    $this->size = ModifierGroup::factory()->create(['name_en' => 'Size', 'name_zh' => '尺寸', 'is_required' => true, 'max_select' => 1]);
    $this->toppings = ModifierGroup::factory()->create(['name_en' => 'Toppings', 'name_zh' => '配料', 'max_select' => 3]);
    $this->tea->modifierGroups()->attach([$this->size->id => ['sort_order' => 1], $this->toppings->id => ['sort_order' => 2]]);

    $this->medium = ModifierOption::factory()->for($this->size)->create(['name_en' => 'Medium', 'name_zh' => '中杯', 'price_delta_cents' => 0, 'sort_order' => 1]);
    $this->large = ModifierOption::factory()->for($this->size)->create(['name_en' => 'Large', 'name_zh' => '大杯', 'price_delta_cents' => 50, 'sort_order' => 2]);
    $this->pearls = ModifierOption::factory()->for($this->toppings)->create(['name_en' => 'Pearls', 'name_zh' => '珍珠', 'price_delta_cents' => 75, 'sort_order' => 1]);
    $this->pudding = ModifierOption::factory()->for($this->toppings)->create(['name_en' => 'Pudding', 'name_zh' => '布丁', 'price_delta_cents' => 100, 'sort_order' => 2]);

    $this->soda = MenuItem::factory()->carbonated()->create(['name_en' => 'Order Soda', 'name_zh' => '訂單汽水', 'price_cents' => 500, 'prep_minutes' => 2]);

    $this->user = User::factory()->create();
    $this->cart = app(Cart::class);
});

/**
 * Put drinks in the session cart.
 *
 * @param  list<array{0: MenuItem, 1: list<ModifierOption>, 2: int}>  $lines
 */
function fillCart(array $lines): CartContents
{
    foreach ($lines as [$item, $options, $quantity]) {
        app(Cart::class)->add(app(ModifierValidator::class)->validate(
            MenuItem::findOrFail($item->id),
            array_map(fn (ModifierOption $o) => $o->id, $options),
            $quantity,
        ));
    }

    return app(Cart::class)->contents();
}

/**
 * Large tea with pearls x2, and a soda.
 */
function standardCart(object $t): CartContents
{
    return fillCart([[$t->tea, [$t->large, $t->pearls], 2], [$t->soda, [], 1]]);
}

function placeFor(User $user, CartContents $cart, PickupType $type = PickupType::Asap, ?CarbonImmutable $pickupAt = null, string $key = 'key-1'): Order
{
    return app(OrderService::class)->place($user, $cart, $type, $pickupAt, $key);
}

/**
 * Run a call that should fail and return the errors.
 *
 * @return array<string, list<string>>
 */
function orderErrors(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Expected a ValidationException.');
}

function vancouverToday(string $time): CarbonImmutable
{
    return CarbonImmutable::parse('2026-11-18 '.$time, 'America/Vancouver');
}

/**
 * Order numbers come from this list, in order.
 *
 * @param  list<string>  $numbers
 */
function fixOrderNumbers(array $numbers): void
{
    app()->instance(OrderNumberGenerator::class, new class($numbers) extends OrderNumberGenerator
    {
        /** @param list<string> $numbers */
        public function __construct(private array $numbers) {}

        public function next(): string
        {
            return array_shift($this->numbers) ?? throw new RuntimeException('Out of order numbers.');
        }
    });
}

describe('placing an order', function () {
    test('writes the order, items, and modifiers with snapshots', function () {
        $order = placeFor($this->user, standardCart($this));

        expect($order->user_id)->toBe($this->user->id)
            ->and($order->status)->toBe(OrderStatus::PendingPayment)
            ->and($order->pickup_type)->toBe(PickupType::Asap)
            ->and($order->order_number)->toMatch('/^TA-\d{6}$/')
            ->and($order->idempotency_key)->toBe('key-1')
            ->and($order->paid_at)->toBeNull()
            ->and($order->promo_code)->toBeNull();

        $items = OrderItem::with('modifiers')->where('order_id', $order->id)->orderBy('id')->get();

        expect($items)->toHaveCount(2)
            ->and($items[0]->only(['menu_item_id', 'name_en', 'name_zh', 'tax_category', 'unit_price_cents', 'quantity']))->toBe([
                'menu_item_id' => $this->tea->id,
                'name_en' => 'Order Tea',
                'name_zh' => '訂單茶',
                'tax_category' => 'standard',
                'unit_price_cents' => 725,
                'quantity' => 2,
            ])
            ->and($items[0]->modifiers->sortBy('id')->map->only(['modifier_option_id', 'group_name_en', 'group_name_zh', 'name_en', 'name_zh', 'price_delta_cents'])->values()->all())->toBe([
                ['modifier_option_id' => $this->large->id, 'group_name_en' => 'Size', 'group_name_zh' => '尺寸', 'name_en' => 'Large', 'name_zh' => '大杯', 'price_delta_cents' => 50],
                ['modifier_option_id' => $this->pearls->id, 'group_name_en' => 'Toppings', 'group_name_zh' => '配料', 'name_en' => 'Pearls', 'name_zh' => '珍珠', 'price_delta_cents' => 75],
            ])
            ->and($items[1]->only(['menu_item_id', 'name_en', 'tax_category', 'unit_price_cents', 'quantity']))->toBe([
                'menu_item_id' => $this->soda->id,
                'name_en' => 'Order Soda',
                'tax_category' => 'carbonated',
                'unit_price_cents' => 500,
                'quantity' => 1,
            ])
            ->and($items[1]->modifiers)->toBeEmpty();
    });

    test('the amounts match PricingService', function () {
        $cart = standardCart($this);
        $expected = app(PricingService::class)->price($cart->valid);

        $order = placeFor($this->user, $cart);

        // 2 x 7.25 + 5.00 = 19.50; GST 5% of 19.50 = 0.98 (0.975 rounded); PST 7% of 5.00 = 0.35.
        expect($order->only(['subtotal_cents', 'discount_cents', 'gst_cents', 'pst_cents', 'total_cents']))->toBe([
            'subtotal_cents' => $expected->subtotal_cents,
            'discount_cents' => 0,
            'gst_cents' => $expected->gst_cents,
            'pst_cents' => $expected->pst_cents,
            'total_cents' => $expected->total_cents,
        ])->and($order->total_cents)->toBe(1950 + 98 + 35);
    });

    test('ASAP stores now plus the longest prep time, in UTC', function () {
        $order = placeFor($this->user, standardCart($this));

        expect($order->pickup_at->equalTo(vancouverToday('12:05')))->toBeTrue()
            ->and(DB::table('orders')->value('pickup_at'))->toBe(vancouverToday('12:05')->utc()->format('Y-m-d H:i:s'));
    });

    test('changing prices afterwards does not change the order', function () {
        $order = placeFor($this->user, standardCart($this));

        $this->tea->update(['price_cents' => 900, 'name_en' => 'Renamed Tea']);
        $this->pearls->update(['price_delta_cents' => 300, 'name_en' => 'Big Pearls']);

        $item = OrderItem::with('modifiers')->where('order_id', $order->id)->where('menu_item_id', $this->tea->id)->firstOrFail();

        expect($order->fresh()->total_cents)->toBe($order->total_cents)
            ->and($item->name_en)->toBe('Order Tea')
            ->and($item->unit_price_cents)->toBe(725)
            ->and($item->modifiers->firstWhere('modifier_option_id', $this->pearls->id)->only(['name_en', 'price_delta_cents']))
            ->toBe(['name_en' => 'Pearls', 'price_delta_cents' => 75]);
    });

    test('a price changed after the cart was loaded is charged at the new price', function () {
        $cart = standardCart($this);
        $this->tea->update(['price_cents' => 700]);

        // 2 x 8.25 + 5.00 = 21.50
        expect(placeFor($this->user, $cart)->subtotal_cents)->toBe(2150);
    });
});

describe('re-validation', function () {
    test('a drink sold out after it was added blocks the order', function () {
        $cart = standardCart($this);
        $this->soda->update(['is_available' => false]);

        $errors = orderErrors(fn () => placeFor($this->user, $cart));

        expect($errors['cart'])->toContain('Order Soda: This drink is sold out.')
            ->and(Order::count())->toBe(0);
    });

    test('an option sold out after it was added blocks the order', function () {
        $cart = standardCart($this);
        $this->pearls->update(['is_available' => false]);

        expect(orderErrors(fn () => placeFor($this->user, $cart))['cart'])->toContain('Order Tea: Pearls is sold out.')
            ->and(Order::count())->toBe(0);
    });

    test('a deactivated category blocks the order', function () {
        $cart = standardCart($this);
        $this->tea->category->update(['is_active' => false]);

        expect(orderErrors(fn () => placeFor($this->user, $cart))['cart'])->toContain('Order Tea: This drink is not available.')
            ->and(Order::count())->toBe(0);
    });

    test('a deleted drink blocks the order', function () {
        $cart = standardCart($this);
        $this->soda->delete();

        expect(orderErrors(fn () => placeFor($this->user, $cart))['cart'])->toContain('Order Soda is no longer on the menu.')
            ->and(Order::count())->toBe(0);
    });

    test('an empty cart is rejected', function () {
        expect(orderErrors(fn () => placeFor($this->user, new CartContents([], []))))->toHaveKey('cart')
            ->and(Order::count())->toBe(0);
    });

    test('a cart with invalid lines is rejected', function () {
        standardCart($this);
        $this->soda->update(['is_available' => false]);

        $cart = $this->cart->contents();

        expect($cart->hasInvalid())->toBeTrue()
            ->and(orderErrors(fn () => placeFor($this->user, $cart)))->toHaveKey('cart')
            ->and(Order::count())->toBe(0);
    });
});

describe('ordering window and pickup time', function () {
    test('ordering closed is blocked on the server', function (string $time) {
        $cart = standardCart($this);
        $this->travelTo(vancouverToday($time));

        expect(orderErrors(fn () => placeFor($this->user, $cart)))->toHaveKey('ordering')
            ->and(Order::count())->toBe(0);
    })->with(['before opening' => '10:30:00', 'after the cutoff' => '21:50:00']);

    test('a scheduled slot is stored in UTC', function () {
        $order = placeFor($this->user, standardCart($this), PickupType::Scheduled, vancouverToday('15:00'));

        expect($order->pickup_type)->toBe(PickupType::Scheduled)
            ->and($order->pickup_at->equalTo(vancouverToday('15:00')))->toBeTrue()
            ->and(DB::table('orders')->value('pickup_at'))->toBe(vancouverToday('15:00')->utc()->format('Y-m-d H:i:s'));
    });

    test('the earliest and latest slots are accepted', function (string $time) {
        // Now 12:00 + 5 minutes prep = 12:05, so the earliest slot is 12:15. Ordering ends at 21:45.
        expect(placeFor($this->user, standardCart($this), PickupType::Scheduled, vancouverToday($time))->exists)->toBeTrue();
    })->with(['12:15', '21:45']);

    test('a time that is not a slot right now is rejected', function (CarbonImmutable $pickupAt) {
        expect(orderErrors(fn () => placeFor($this->user, standardCart($this), PickupType::Scheduled, $pickupAt)))->toHaveKey('pickup')
            ->and(Order::count())->toBe(0);
    })->with([
        'too soon' => fn () => vancouverToday('12:00'),
        'not on a mark' => fn () => vancouverToday('15:10'),
        'after ordering ends' => fn () => vancouverToday('22:00'),
        'tomorrow' => fn () => vancouverToday('15:00')->addDay(),
    ]);

    test('a scheduled order needs a time', function () {
        expect(orderErrors(fn () => placeFor($this->user, standardCart($this), PickupType::Scheduled)))->toHaveKey('pickup');
    });

    test('ASAP ignores a pickup time from the client', function () {
        $order = placeFor($this->user, standardCart($this), PickupType::Asap, vancouverToday('20:00'));

        expect($order->pickup_at->equalTo(vancouverToday('12:05')))->toBeTrue();
    });
});

describe('idempotency', function () {
    test('the same key twice gives one order', function () {
        $first = placeFor($this->user, standardCart($this));
        $second = placeFor($this->user, $this->cart->contents());

        expect($second->id)->toBe($first->id)
            ->and(Order::count())->toBe(1)
            ->and(OrderItem::count())->toBe(2);
    });

    test('a repeated submit finds its order even after the cart was emptied', function () {
        $first = placeFor($this->user, standardCart($this));
        $this->cart->clear();

        expect(placeFor($this->user, $this->cart->contents())->id)->toBe($first->id);
    });

    test('different users with the same key each get their own order', function () {
        $other = User::factory()->create();
        $mine = placeFor($this->user, standardCart($this), key: 'shared');
        $theirs = placeFor($other, $this->cart->contents(), key: 'shared');

        expect($theirs->id)->not->toBe($mine->id)
            ->and($theirs->user_id)->toBe($other->id)
            ->and($mine->user_id)->toBe($this->user->id)
            ->and(Order::count())->toBe(2);
    });

    test('a simultaneous submit that wins the race is returned, and nothing else is written', function () {
        $cart = standardCart($this);

        // Simulate the other request committing between our lookup and our insert: the
        // number generator runs just before the insert (outside its savepoint).
        $competitorId = null;
        app()->instance(OrderNumberGenerator::class, new class($this->user, $competitorId) extends OrderNumberGenerator
        {
            public function __construct(private User $user, private ?int &$competitorId) {}

            public function next(): string
            {
                $this->competitorId ??= DB::table('orders')->insertGetId([
                    'order_number' => 'TA-999999',
                    'user_id' => $this->user->id,
                    'status' => OrderStatus::PendingPayment->value,
                    'pickup_type' => PickupType::Asap->value,
                    'subtotal_cents' => 1950,
                    'discount_cents' => 0,
                    'gst_cents' => 98,
                    'pst_cents' => 35,
                    'total_cents' => 2083,
                    'idempotency_key' => 'key-1',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return 'TA-000123';
            }
        });

        $order = placeFor($this->user, $cart);

        expect($order->id)->toBe($competitorId)
            ->and($order->order_number)->toBe('TA-999999')
            ->and(Order::count())->toBe(1)
            ->and(OrderItem::count())->toBe(0);
    });
});

describe('order numbers', function () {
    test('a colliding number is retried', function () {
        Order::factory()->create(['order_number' => 'TA-000001']);
        fixOrderNumbers(['TA-000001', 'TA-000001', 'TA-000002']);

        expect(placeFor($this->user, standardCart($this))->order_number)->toBe('TA-000002');
    });

    test('five collisions give up with an error and write nothing', function () {
        Order::factory()->create(['order_number' => 'TA-000001']);
        fixOrderNumbers(array_fill(0, OrderService::MAX_NUMBER_ATTEMPTS, 'TA-000001'));

        expect(orderErrors(fn () => placeFor($this->user, standardCart($this))))->toHaveKey('order')
            ->and(Order::count())->toBe(1)
            ->and(OrderItem::count())->toBe(0);
    });

    test('the generator makes TA- plus 6 digits', function () {
        $generator = new OrderNumberGenerator;

        foreach (range(1, 50) as $i) {
            expect($generator->next())->toMatch('/^TA-\d{6}$/');
        }
    });
});

test('a failure partway through rolls everything back', function () {
    $cart = standardCart($this);
    OrderItemModifier::creating(fn () => throw new RuntimeException('Disk full'));

    expect(fn () => placeFor($this->user, $cart))->toThrow(RuntimeException::class, 'Disk full');

    expect(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0)
        ->and(OrderItemModifier::count())->toBe(0);
});

describe('fake payment gateway', function () {
    test('marks the order paid and returns the receipt URL', function () {
        $order = placeFor($this->user, standardCart($this));

        $url = app(PaymentGateway::class)->startPayment($order);

        expect($url)->toBe(url('/orders/'.$order->order_number))
            ->and($order->fresh()->status)->toBe(OrderStatus::Paid)
            ->and($order->fresh()->paid_at->equalTo(now()))->toBeTrue();
    });

    test('paying again changes nothing', function () {
        $order = placeFor($this->user, standardCart($this));
        app(PaymentGateway::class)->startPayment($order);
        $paidAt = $order->fresh()->paid_at;

        $this->travel(5)->minutes();
        app(PaymentGateway::class)->startPayment($order);

        expect($order->fresh()->paid_at->equalTo($paidAt))->toBeTrue();
    });
});
