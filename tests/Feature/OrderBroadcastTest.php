<?php

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Exceptions\InvalidOrderTransition;
use App\Models\Order;
use Database\Seeders\RoleSeeder;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

function authorizeChannel(string $channel): TestResponse
{
    return test()->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);
}

describe('dispatch', function () {
    test('every legal transition dispatches OrderStatusChanged with only the order id and status', function (string $move) {
        [$from, $to] = array_map(OrderStatus::from(...), explode('>', $move));
        $order = Order::factory()->create(['status' => $from]);
        Event::fake([OrderStatusChanged::class]);

        $order->transitionTo($to);

        Event::assertDispatchedTimes(OrderStatusChanged::class, 1);
        Event::assertDispatched(function (OrderStatusChanged $event) use ($order, $to): bool {
            expect($event->broadcastWith())->toBe(['order_id' => $order->id, 'status' => $to->value])
                ->and(array_keys(get_object_vars($event)))->toBe(['orderId', 'status'])
                ->and(array_map(fn ($channel) => $channel->name, $event->broadcastOn()))
                ->toBe(['private-barista', 'private-orders.'.$order->id]);

            return true;
        });
    })->with(legalOrderTransitions());

    test('a refused transition dispatches nothing', function () {
        $order = Order::factory()->create(['status' => OrderStatus::PickedUp]);
        Event::fake([OrderStatusChanged::class]);

        expect(fn () => $order->transitionTo(OrderStatus::Preparing))->toThrow(InvalidOrderTransition::class);

        Event::assertNotDispatched(OrderStatusChanged::class);
    });
});

/*
 * Reverb down: the broadcaster throws. The transition must still succeed, and the failure
 * must be logged (ShouldRescue reports it; the exception handler logs it).
 */
describe('broadcast failure', function () {
    beforeEach(function () {
        Broadcast::extend('throwing', fn () => new class extends Broadcaster
        {
            public function auth($request): mixed
            {
                return null;
            }

            public function validAuthenticationResponse($request, $result): mixed
            {
                return null;
            }

            /** @param  array<int, mixed>  $channels */
            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new BroadcastException('Reverb is down');
            }
        });
        config([
            'broadcasting.connections.throwing' => ['driver' => 'throwing'],
            'broadcasting.default' => 'throwing',
            'logging.default' => 'null',
        ]);

        $this->logged = [];
        Event::listen(MessageLogged::class, fn (MessageLogged $log) => $this->logged[] = $log);
    });

    test('the transition still succeeds and the failure is logged', function () {
        $order = Order::factory()->create(['status' => OrderStatus::Preparing]);

        $order->transitionTo(OrderStatus::Ready);

        expect($order->fresh()->status)->toBe(OrderStatus::Ready)
            ->and($this->logged)->toHaveCount(1)
            ->and($this->logged[0]->level)->toBe('error')
            ->and($this->logged[0]->context['exception'])->toBeInstanceOf(BroadcastException::class);
    });

    test('inside a transaction the broadcast waits for the commit, and its failure is still caught and logged', function () {
        $order = Order::factory()->create(['status' => OrderStatus::Preparing]);

        DB::transaction(function () use ($order) {
            $order->transitionTo(OrderStatus::Ready);

            expect($this->logged)->toBe([]);  // not sent yet
        });

        expect($order->fresh()->status)->toBe(OrderStatus::Ready)
            ->and($this->logged)->toHaveCount(1)
            ->and($this->logged[0]->context['exception'])->toBeInstanceOf(BroadcastException::class);
    });
});

/*
 * Channel authorization through the real /broadcasting/auth route. The suite's broadcaster
 * is "null", which authorizes nothing, so these tests switch to the reverb driver (it signs
 * the response locally; no server is contacted) and register the channels on it.
 */
describe('channel authorization', function () {
    beforeEach(function () {
        $this->seed(RoleSeeder::class);
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
        ]);
        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');
    });

    test('the barista channel is for baristas, managers, and admins', function (string $role, int $status) {
        $this->actingAs(userWithRole($role));

        authorizeChannel('private-barista')->assertStatus($status);
    })->with([
        'barista' => ['barista', 200],
        'manager' => ['manager', 200],
        'admin' => ['admin', 200],
        'customer' => ['customer', 403],
    ]);

    test('guests can\'t join the barista channel', function () {
        authorizeChannel('private-barista')->assertForbidden();
    });

    test('an order\'s channel is only for its owner', function () {
        $order = Order::factory()->paid()->create();

        $this->actingAs($order->user);
        authorizeChannel('private-orders.'.$order->id)->assertOk()->assertJsonStructure(['auth']);

        $this->actingAs(userWithRole('customer'));
        authorizeChannel('private-orders.'.$order->id)->assertForbidden();

        // Staff don't get customers' order channels either: the status page is owner-only.
        $this->actingAs(userWithRole('admin'));
        authorizeChannel('private-orders.'.$order->id)->assertForbidden();
    });

    test('guests can\'t join an order\'s channel', function () {
        $order = Order::factory()->paid()->create();

        authorizeChannel('private-orders.'.$order->id)->assertForbidden();
    });

    test('an unknown order\'s channel is refused', function () {
        $this->actingAs(userWithRole('customer'));

        authorizeChannel('private-orders.999999')->assertForbidden();
    });
});
