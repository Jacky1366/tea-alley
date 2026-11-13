<?php

use App\Enums\OrderStatus;
use App\Enums\PickupType;
use App\Enums\PromoType;
use App\Models\MenuItem;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\PromoCode;
use App\Models\StoreHour;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\StoreHoursSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

// Relationships

test('a user has many orders', function () {
    $user = User::factory()->create();
    Order::factory()->count(2)->for($user)->create();

    expect($user->orders)->toHaveCount(2)
        ->each->toBeInstanceOf(Order::class);
});

test('an order belongs to a user', function () {
    $user = User::factory()->create();
    $order = Order::factory()->for($user)->create();

    expect($order->user->is($user))->toBeTrue();
});

test('an order belongs to a promo code', function () {
    $promo = PromoCode::factory()->create();
    $order = Order::factory()->for($promo)->create();

    expect($order->promoCode?->is($promo))->toBeTrue();
});

test('a promo code has many orders', function () {
    $promo = PromoCode::factory()->create();
    Order::factory()->count(3)->for($promo)->create();

    expect($promo->orders)->toHaveCount(3)
        ->each->toBeInstanceOf(Order::class);
});

test('an order has many items', function () {
    $order = Order::factory()->create();
    OrderItem::factory()->count(2)->for($order)->create();

    expect($order->items)->toHaveCount(2)
        ->each->toBeInstanceOf(OrderItem::class);
});

test('an order item belongs to an order', function () {
    $order = Order::factory()->create();
    $item = OrderItem::factory()->for($order)->create();

    expect($item->order->is($order))->toBeTrue();
});

test('an order item belongs to a menu item', function () {
    $menuItem = MenuItem::factory()->create();
    $item = OrderItem::factory()->forMenuItem($menuItem)->create();

    expect($item->menuItem?->is($menuItem))->toBeTrue();
});

test('an order item has many modifiers', function () {
    $item = OrderItem::factory()->create();
    OrderItemModifier::factory()->count(3)->for($item)->create();

    expect($item->modifiers)->toHaveCount(3)
        ->each->toBeInstanceOf(OrderItemModifier::class);
});

test('an order item modifier belongs to an order item', function () {
    $item = OrderItem::factory()->create();
    $modifier = OrderItemModifier::factory()->for($item)->create();

    expect($modifier->orderItem->is($item))->toBeTrue();
});

test('an order item modifier belongs to a modifier option', function () {
    $option = ModifierOption::factory()->create();
    $modifier = OrderItemModifier::factory()->forOption($option)->create();

    expect($modifier->modifierOption?->is($option))->toBeTrue();
});

// Delete behaviour

test('deleting a menu item nulls order items but keeps the snapshot', function () {
    $menuItem = MenuItem::factory()->create(['name_en' => 'Taro Milk Tea', 'price_cents' => 645]);
    $item = OrderItem::factory()->forMenuItem($menuItem)->create();

    $menuItem->delete();
    $item->refresh();

    expect($item->menu_item_id)->toBeNull()
        ->and($item->name_en)->toBe('Taro Milk Tea')
        ->and($item->name_zh)->toBe($menuItem->name_zh)
        ->and($item->tax_category)->toBe(MenuItem::TAX_STANDARD)
        ->and($item->unit_price_cents)->toBe(645);
});

test('deleting a modifier option nulls order item modifiers but keeps the snapshot', function () {
    $option = ModifierOption::factory()->create(['name_en' => 'Pearls', 'price_delta_cents' => 75]);
    $modifier = OrderItemModifier::factory()->forOption($option)->create();

    $option->delete();
    $modifier->refresh();

    expect($modifier->modifier_option_id)->toBeNull()
        ->and($modifier->name_en)->toBe('Pearls')
        ->and($modifier->group_name_en)->toBe($option->modifierGroup->name_en)
        ->and($modifier->price_delta_cents)->toBe(75);
});

test('deleting a promo code nulls orders but keeps the code snapshot', function () {
    $promo = PromoCode::factory()->create(['code' => 'WELCOME10']);
    $order = Order::factory()->for($promo)->create(['promo_code' => 'WELCOME10', 'discount_cents' => 100]);

    $promo->delete();
    $order->refresh();

    expect($order->promo_code_id)->toBeNull()
        ->and($order->promo_code)->toBe('WELCOME10')
        ->and($order->discount_cents)->toBe(100);
});

test('deleting a user with orders is blocked by the database', function () {
    $user = User::factory()->create();
    Order::factory()->for($user)->create();

    expect(fn () => $user->delete())->toThrow(QueryException::class);

    expect(User::whereKey($user->id)->exists())->toBeTrue();
});

test('deleting an order deletes its items and their modifiers', function () {
    $order = Order::factory()->create();
    $items = OrderItem::factory()->count(2)->for($order)->create();
    $items->each(fn (OrderItem $item) => OrderItemModifier::factory()->count(2)->for($item)->create());

    $order->delete();

    expect(OrderItem::count())->toBe(0)
        ->and(OrderItemModifier::count())->toBe(0);
});

// Unique constraints

test('order numbers are unique', function () {
    Order::factory()->create(['order_number' => 'TA-1042']);

    expect(fn () => Order::factory()->create(['order_number' => 'TA-1042']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('idempotency keys are unique', function () {
    $key = str_repeat('a', 64);
    Order::factory()->create(['idempotency_key' => $key]);

    expect(fn () => Order::factory()->create(['idempotency_key' => $key]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('store hours days of week are unique', function () {
    StoreHour::factory()->create(['day_of_week' => 1]);

    expect(fn () => StoreHour::factory()->create(['day_of_week' => 1]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('orders without a stripe session do not collide on the unique index', function () {
    Order::factory()->count(2)->create(['stripe_session_id' => null]);

    expect(Order::whereNull('stripe_session_id')->count())->toBe(2);
});

// Casts and defaults

test('enums are stored as strings and read back as enums', function () {
    $order = Order::factory()->create([
        'status' => OrderStatus::Paid,
        'pickup_type' => PickupType::Scheduled,
        'pickup_at' => now()->addHour(),
    ]);
    $promo = PromoCode::factory()->fixed(250)->create();

    $orderRow = DB::table('orders')->where('id', $order->id)->first();
    $promoRow = DB::table('promo_codes')->where('id', $promo->id)->first();

    expect($orderRow?->status)->toBe('paid')
        ->and($orderRow?->pickup_type)->toBe('scheduled')
        ->and($promoRow?->type)->toBe('fixed');

    expect($order->fresh()?->status)->toBe(OrderStatus::Paid)
        ->and($order->fresh()?->pickup_type)->toBe(PickupType::Scheduled)
        ->and($promo->fresh()?->type)->toBe(PromoType::Fixed);
});

test('database defaults are readable right after create', function () {
    $order = Order::create([
        'order_number' => 'TA-2001',
        'user_id' => User::factory()->create()->id,
        'pickup_type' => PickupType::Asap,
        'subtotal_cents' => 595,
        'gst_cents' => 30,
        'pst_cents' => 0,
        'total_cents' => 625,
        'idempotency_key' => str_repeat('b', 64),
    ]);
    $promo = PromoCode::create(['code' => 'SPRING', 'type' => PromoType::Percent, 'value' => 10]);
    $hours = StoreHour::create(['day_of_week' => 3, 'opens_at' => '11:00:00', 'closes_at' => '21:00:00']);

    expect($order->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->discount_cents)->toBe(0)
        ->and($promo->used_count)->toBe(0)
        ->and($promo->is_active)->toBeTrue()
        ->and($hours->is_closed)->toBeFalse()
        ->and($hours->ordering_cutoff_minutes)->toBe(15);

    $fresh = $order->fresh();

    expect($fresh?->status)->toBe(OrderStatus::PendingPayment)
        ->and($fresh?->discount_cents)->toBe(0);
});

test('order timestamps are cast to dates', function () {
    $order = Order::factory()->paid()->scheduled()->create()->fresh();

    expect($order?->paid_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($order?->pickup_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($order?->ready_at)->toBeNull();
});

// Seeders

test('the database seeder creates store hours for every weekday', function () {
    $this->seed(DatabaseSeeder::class);

    expect(StoreHour::count())->toBe(7)
        ->and(StoreHour::orderBy('day_of_week')->pluck('day_of_week')->all())->toBe([0, 1, 2, 3, 4, 5, 6]);
});

test('the store hours seeder can run twice', function () {
    $this->seed(StoreHoursSeeder::class);
    $this->seed(StoreHoursSeeder::class);

    expect(StoreHour::count())->toBe(7);
});
