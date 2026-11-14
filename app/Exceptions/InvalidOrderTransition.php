<?php

namespace App\Exceptions;

use App\Enums\OrderStatus;
use LogicException;

/**
 * The state machine (OrderStatus::canTransitionTo) doesn't allow this move.
 */
class InvalidOrderTransition extends LogicException
{
    public function __construct(
        public readonly int $orderId,
        public readonly OrderStatus $from,
        public readonly OrderStatus $to,
    ) {
        parent::__construct("Order {$orderId} cannot move from {$from->value} to {$to->value}.");
    }
}
