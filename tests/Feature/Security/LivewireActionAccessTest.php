<?php

use App\Enums\OrderStatus;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\MenuItems\Pages\ListMenuItems;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Services\Cart;
use App\Services\ModifierValidator;
use Database\Seeders\RoleSeeder;
use Illuminate\Testing\TestResponse;

/*
 * T3-3 (#51): Livewire actions go to POST /livewire/update, not to the page's route, so the
 * route's middleware only protects them if Livewire re-applies it (persistent middleware)
 * and the action checks a policy. These tests do what an attacker would: render the page as
 * someone who may see it, take the component's real snapshot, and send an action with it as
 * someone else, through the real HTTP endpoint (Livewire::test() skips middleware).
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    setStoreHours(array_fill(0, 7, ['00:00:00', '23:59:00']));
});

/**
 * The wire:snapshot of the first component named $component on $url, as $user sees it.
 */
function snapshotFor(User $user, string $url, string $component): string
{
    $html = test()->actingAs($user)->get($url)->assertOk()->getContent();

    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5);

        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === $component) {
            return $snapshot;
        }
    }

    throw new RuntimeException("No {$component} snapshot on {$url}.");
}

/**
 * Sends one action through POST /livewire/update, as the client would, as $user (or a guest).
 *
 * @param  list<mixed>  $params
 */
function livewireAction(?User $user, string $snapshot, string $method, array $params = []): TestResponse
{
    auth()->forgetGuards();
    $request = $user === null ? test() : test()->actingAs($user);

    // A valid CSRF token, like the real client: tests skip the CSRF check only while the
    // environment is "testing", and one test switches to production.
    return $request->withSession(['_token' => 'csrf-token'])->withHeaders([
        'X-Livewire' => 'true',
        'X-CSRF-TOKEN' => 'csrf-token',
    ])->postJson(route('default-livewire.update'), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['method' => $method, 'params' => $params, 'metadata' => []]],
        ]],
    ]);
}

describe('barista board (role:barista|manager|admin, OrderPolicy::advance)', function () {
    test('a customer replaying a barista\'s snapshot cannot move an order', function (string $action, OrderStatus $from) {
        $order = Order::factory()->create(['status' => $from, 'paid_at' => now()]);
        $snapshot = snapshotFor(userWithRole('barista'), route('barista'), 'pages::barista-board');

        livewireAction(userWithRole('customer'), $snapshot, $action, [$order->id])->assertForbidden();

        expect($order->fresh()->status)->toBe($from);
    })->with([
        'Start' => ['start', OrderStatus::Paid],
        'Bump' => ['bump', OrderStatus::Preparing],
        'Recall' => ['recall', OrderStatus::Ready],
        'Picked up' => ['pickUp', OrderStatus::Ready],
    ]);

    test('a guest replaying it is refused too', function () {
        $order = Order::factory()->paid()->create();
        $snapshot = snapshotFor(userWithRole('barista'), route('barista'), 'pages::barista-board');

        livewireAction(null, $snapshot, 'start', [$order->id])->assertUnauthorized();

        expect($order->fresh()->status)->toBe(OrderStatus::Paid);
    });

    test('the same request as another barista works, so the refusals above are real', function () {
        $order = Order::factory()->paid()->create();
        $snapshot = snapshotFor(userWithRole('barista'), route('barista'), 'pages::barista-board');

        livewireAction(userWithRole('barista'), $snapshot, 'start', [$order->id])->assertOk();

        expect($order->fresh()->status)->toBe(OrderStatus::Preparing);
    });
});

describe('order status page (can:view,order, OrderPolicy::pay / simulatePayment)', function () {
    test('another customer replaying the owner\'s snapshot gets a 404, and nothing changes', function (string $action) {
        $owner = userWithRole('customer');
        $order = Order::factory()->for($owner)->create(['status' => OrderStatus::PendingPayment]);
        $snapshot = snapshotFor($owner, route('orders.show', $order), 'pages::orders.show');

        livewireAction(userWithRole('customer'), $snapshot, $action)->assertNotFound();

        expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    })->with(['retryPayment', 'simulatePayment', '$refresh']);

    test('staff can\'t use a customer\'s receipt either', function (string $role) {
        $owner = userWithRole('customer');
        $order = Order::factory()->for($owner)->create(['status' => OrderStatus::PendingPayment]);
        $snapshot = snapshotFor($owner, route('orders.show', $order), 'pages::orders.show');

        livewireAction(userWithRole($role), $snapshot, 'simulatePayment')->assertNotFound();

        expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    })->with(['barista', 'manager', 'admin']);

    test('Simulate payment is refused outside the local environment, even for the admin who owns the order', function () {
        $admin = userWithRole('admin');
        $order = Order::factory()->for($admin)->create(['status' => OrderStatus::PendingPayment]);
        app()->detectEnvironment(fn () => 'production');
        $snapshot = snapshotFor($admin, route('orders.show', $order), 'pages::orders.show');

        livewireAction($admin, $snapshot, 'simulatePayment')->assertForbidden();

        expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    });
});

describe('checkout (auth + verified)', function () {
    beforeEach(function () {
        // Checkout sends an empty cart back to the cart page; put one drink in the session's cart.
        app(Cart::class)->add(app(ModifierValidator::class)->validate(MenuItem::factory()->create(), [], 1));
    });

    test('a user whose email isn\'t verified cannot place an order with a verified user\'s snapshot (G1)', function () {
        $snapshot = snapshotFor(userWithRole('customer'), route('checkout'), 'pages::checkout');
        $unverified = userWithRole('customer');
        $unverified->forceFill(['email_verified_at' => null])->save();

        // EnsureEmailIsVerified now runs on Livewire requests: a JSON request gets a 403.
        livewireAction($unverified, $snapshot, 'placeOrder')->assertForbidden();

        expect(Order::count())->toBe(0);
    });

    test('a guest cannot place an order with someone\'s snapshot', function () {
        $snapshot = snapshotFor(userWithRole('customer'), route('checkout'), 'pages::checkout');

        livewireAction(null, $snapshot, 'placeOrder')->assertUnauthorized();

        expect(Order::count())->toBe(0);
    });
});

describe('admin panel (canAccessPanel, policies)', function () {
    test('a customer or barista replaying a manager\'s drinks table cannot toggle sold out', function (string $role) {
        $drink = MenuItem::factory()->create();
        $snapshot = snapshotFor(userWithRole('manager'), '/admin/menu-items', ListMenuItems::class);

        livewireAction(userWithRole($role), $snapshot, 'updateTableColumnState', ['is_available', strval($drink->id), false])->assertForbidden();

        expect($drink->fresh()->is_available)->toBeTrue();
    })->with(['customer', 'barista']);

    test('a manager replaying an admin\'s audit log is refused', function () {
        $snapshot = snapshotFor(userWithRole('admin'), '/admin/audit-logs', ListAuditLogs::class);

        livewireAction(userWithRole('manager'), $snapshot, '$refresh')->assertForbidden();
    });
});
