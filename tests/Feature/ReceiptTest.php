<?php

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
use App\Services\ModifierValidator;
use App\Services\OrderService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/*
 * Fixture: "Receipt Tea" ($6.00, prep 5) with Size (Medium +0, Large +50) and Toppings
 * (Pearls +75, Pudding +100), and "Receipt Soda" ($5.00, carbonated, prep 2).
 * Ordering is open 11:00-22:00 every day; the clock is Wed Nov 18, 2026 12:00 in Vancouver.
 */
beforeEach(function () {
    setStoreHours(array_fill(0, 7, ['11:00:00', '22:00:00']));
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'America/Vancouver'));

    $this->tea = MenuItem::factory()->create(['name_en' => 'Receipt Tea', 'name_zh' => '收據茶', 'price_cents' => 600, 'prep_minutes' => 5]);
    $size = ModifierGroup::factory()->create(['name_en' => 'Size', 'is_required' => true, 'max_select' => 1]);
    $toppings = ModifierGroup::factory()->create(['name_en' => 'Toppings', 'max_select' => 3]);
    $this->tea->modifierGroups()->attach([$size->id => ['sort_order' => 1], $toppings->id => ['sort_order' => 2]]);
    $this->large = ModifierOption::factory()->for($size)->create(['name_en' => 'Large', 'price_delta_cents' => 50, 'sort_order' => 2]);
    ModifierOption::factory()->for($size)->create(['name_en' => 'Medium', 'price_delta_cents' => 0, 'sort_order' => 1]);
    $this->pearls = ModifierOption::factory()->for($toppings)->create(['name_en' => 'Pearls', 'price_delta_cents' => 75, 'sort_order' => 1]);
    $this->pudding = ModifierOption::factory()->for($toppings)->create(['name_en' => 'Pudding', 'price_delta_cents' => 100, 'sort_order' => 2]);

    $this->soda = MenuItem::factory()->carbonated()->create(['name_en' => 'Receipt Soda', 'name_zh' => '收據汽水', 'price_cents' => 500, 'prep_minutes' => 2]);

    $this->user = User::factory()->create();
});

/**
 * Place a real order through OrderService: Large tea with Pudding and Pearls x2, and a soda.
 * 2 x 8.25 + 5.00 = 21.50; GST 1.08 (1.075 rounded); PST 0.35; total 22.93.
 */
function receiptOrder(object $t, PickupType $type = PickupType::Asap, ?CarbonImmutable $pickupAt = null): Order
{
    $validator = app(ModifierValidator::class);
    $cart = app(Cart::class);
    $cart->add($validator->validate(MenuItem::findOrFail($t->tea->id), [$t->pudding->id, $t->large->id, $t->pearls->id], 2));
    $cart->add($validator->validate(MenuItem::findOrFail($t->soda->id), [], 1));

    $order = app(OrderService::class)->place($t->user, $cart->contents(), $type, $pickupAt, 'receipt-'.$type->value);
    $cart->clear();

    return $order;
}

describe('access', function () {
    test('the owner sees the receipt', function () {
        $order = receiptOrder($this);

        $this->actingAs($this->user)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee($order->order_number);
    });

    test('the receipt URL uses the order number', function () {
        $order = receiptOrder($this);

        expect(route('orders.show', $order))->toEndWith('/orders/'.$order->order_number);
    });

    test('guests are sent to log in', function () {
        $order = receiptOrder($this);

        $this->get(route('orders.show', $order))->assertRedirect(route('login'));
    });

    test('unverified users are sent to verify their email', function () {
        $order = receiptOrder($this);
        $this->user->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($this->user)->get(route('orders.show', $order))->assertRedirect(route('verification.notice'));
    });

    test('someone else\'s order gets exactly the same 404 as an unknown order number', function () {
        $order = receiptOrder($this);
        $other = User::factory()->create();

        $theirs = $this->actingAs($other)->get(route('orders.show', $order));
        $unknown = $this->actingAs($other)->get('/orders/TA-000000');

        $theirs->assertNotFound();
        $unknown->assertNotFound();

        expect($theirs->getContent())->toBe($unknown->getContent())
            ->and($theirs->headers->get('Content-Type'))->toBe($unknown->headers->get('Content-Type'));
    });

    test('the policy denies another user as not found', function () {
        $order = receiptOrder($this);

        expect(Gate::forUser($this->user)->inspect('view', $order)->allowed())->toBeTrue()
            ->and(Gate::forUser(User::factory()->create())->inspect('view', $order)->status())->toBe(404);
    });
});

describe('content', function () {
    test('shows the order number, status, placed time, and ASAP pickup in Vancouver time', function () {
        $order = receiptOrder($this);
        $order->update(['status' => OrderStatus::Paid]);

        $this->actingAs($this->user)->get(route('orders.show', $order))
            ->assertSeeInOrder(['data-test="order-number"', $order->order_number], escape: false)
            ->assertSee('Paid')
            ->assertSee('Placed Nov 18, 2026 12:00 PM')
            ->assertSee('As soon as possible, around 12:05 PM');
    });

    test('shows a scheduled pickup time in Vancouver time', function () {
        $order = receiptOrder($this, PickupType::Scheduled, CarbonImmutable::parse('2026-11-18 15:30', 'America/Vancouver'));

        $this->actingAs($this->user)->get(route('orders.show', $order))
            ->assertSee('Scheduled for Wed, Nov 18 3:30 PM');
    });

    test('a pending order says payment is pending', function () {
        $order = receiptOrder($this);

        expect($order->status)->toBe(OrderStatus::PendingPayment);

        $this->actingAs($this->user)->get(route('orders.show', $order))->assertSee('Payment pending');
    });

    test('shows each drink with its options grouped by modifier group', function () {
        $order = receiptOrder($this);

        $this->actingAs($this->user)->get(route('orders.show', $order))
            ->assertSeeInOrder([
                'Receipt Tea × 2', '收據茶', 'Size: Large', 'Toppings: Pearls, Pudding', '$8.25 each', '$16.50',
                'Receipt Soda × 1', '收據汽水', '$5.00 each', '$5.00',
            ]);
    });

    test('the totals are the order columns, and they add up', function () {
        $order = receiptOrder($this);

        expect($order->subtotal_cents - $order->discount_cents + $order->gst_cents + $order->pst_cents)->toBe($order->total_cents)
            ->and($order->total_cents)->toBe(2293);

        $this->actingAs($this->user)->get(route('orders.show', $order))
            ->assertSeeInOrder([
                'data-test="subtotal"', '$21.50',
                'data-test="gst"', '$1.08',
                'data-test="pst"', '$0.35',
                'data-test="total"', '$22.93',
            ], escape: false)
            ->assertDontSee('data-test="discount"', escape: false);
    });

    test('a discount is shown only when there is one, with its code', function () {
        $order = Order::factory()->for($this->user)->create([
            'subtotal_cents' => 1000, 'discount_cents' => 200, 'promo_code' => 'SPRING',
            'gst_cents' => 40, 'pst_cents' => 0, 'total_cents' => 840,
        ]);

        $this->actingAs($this->user)->get(route('orders.show', $order))
            ->assertSee('Discount (SPRING)')
            ->assertSeeInOrder(['data-test="discount"', '$2.00', 'data-test="total"', '$8.40'], escape: false);
    });
});

describe('snapshots', function () {
    test('renaming, repricing, and deleting the menu afterwards changes nothing', function () {
        $order = receiptOrder($this);
        $url = route('orders.show', $order);

        $before = $this->actingAs($this->user)->get($url)->assertOk();

        $this->tea->update(['name_en' => 'Renamed Tea', 'name_zh' => '改名茶', 'price_cents' => 999]);
        $this->pearls->update(['name_en' => 'Giant Pearls', 'price_delta_cents' => 500]);
        $this->pudding->modifierGroup->update(['name_en' => 'Extras']);
        $this->pudding->delete();
        $this->soda->delete();

        $after = $this->actingAs($this->user)->get($url)->assertOk()
            ->assertSee('Receipt Tea × 2')
            ->assertSee('Toppings: Pearls, Pudding')
            ->assertSee('Receipt Soda × 1')
            ->assertSee('$22.93')
            ->assertDontSee('Renamed Tea')
            ->assertDontSee('Giant Pearls')
            ->assertDontSee('Extras');

        // Same receipt section, byte for byte.
        $section = fn (string $html): string => preg_match('/data-test="order-number".*data-test="total"[^<]*<\/[^>]+>[^<]*</s', $html, $m) ? $m[0] : '';
        expect($section($after->getContent()))->not->toBe('')
            ->toBe($section($before->getContent()));
    });

    test('the receipt never queries the menu tables', function () {
        $order = receiptOrder($this);
        $this->actingAs($this->user);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->get(route('orders.show', $order))->assertOk();

        expect(collect($queries)->filter(fn (string $sql) => preg_match('/\b(menu_items|modifier_options|modifier_groups|categories)\b/', $sql))->all())
            ->toBe([]);
    });
});

describe('modifier grouping', function () {
    test('consecutive rows with the same group name form one group, in insertion order', function () {
        $item = OrderItem::factory()->create();
        foreach ([['Size', 'Large'], ['Sugar', '50%'], ['Toppings', 'Pearls'], ['Toppings', 'Pudding']] as [$group, $name]) {
            OrderItemModifier::factory()->for($item)->create(['group_name_en' => $group, 'name_en' => $name]);
        }

        expect($item->load(['modifiers' => fn ($q) => $q->orderBy('id')])->modifierSummary())
            ->toBe(['Size: Large', 'Sugar: 50%', 'Toppings: Pearls, Pudding']);
    });

    test('a drink without options has no groups', function () {
        expect(OrderItem::factory()->create()->load('modifiers')->modifierSummary())->toBe([]);
    });
});

test('checkout ends on the receipt', function () {
    $validator = app(ModifierValidator::class);
    app(Cart::class)->add($validator->validate(MenuItem::findOrFail($this->soda->id), [], 2));

    Livewire::actingAs($this->user)->test('pages::checkout')->call('placeOrder');

    $order = Order::sole();

    expect($order->status)->toBe(OrderStatus::Paid);

    Livewire::actingAs($this->user)->test('pages::checkout')->assertRedirect(route('cart'));

    $this->actingAs($this->user)->get(route('orders.show', $order))
        ->assertOk()
        ->assertSee($order->order_number)
        ->assertSee('Receipt Soda × 2')
        ->assertSee('Paid');
});

test('checkout redirects to the receipt route', function () {
    app(Cart::class)->add(app(ModifierValidator::class)->validate(MenuItem::findOrFail($this->soda->id), [], 1));

    $page = Livewire::actingAs($this->user)->test('pages::checkout')->call('placeOrder');

    $page->assertRedirect(route('orders.show', Order::sole()));
});
