<?php

namespace App\Events;

use App\Enums\OrderStatus;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An order moved to a new status. Order::transitionTo() dispatches it after a successful
 * transition.
 *
 * - ShouldBroadcastNow: sent during the request, no queue worker needed.
 * - ShouldDispatchAfterCommit: inside a transaction it waits for the commit (and is dropped
 *   on rollback); transitionTo() itself is a single autocommitted UPDATE, so normally it goes
 *   out at once.
 * - ShouldRescue: if Reverb is down, BroadcastManager catches the exception and reports it
 *   (logs it) instead of failing the transition. This also covers the after-commit path,
 *   which a try/catch around the dispatch would not.
 *
 * The payload is only the order id and status: no drinks, no customer data. The pages
 * re-read everything else from the database. Scalars only, so no model is serialized.
 */
final class OrderStatusChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    public function __construct(
        public readonly int $orderId,
        public readonly OrderStatus $status,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('barista'),
            new PrivateChannel('orders.'.$this->orderId),
        ];
    }

    /**
     * @return array{order_id: int, status: string}
     */
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->orderId,
            'status' => $this->status->value,
        ];
    }
}
