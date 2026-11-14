<?php

namespace App\Exceptions;

use App\Enums\OrderStatus;
use RuntimeException;

/**
 * The move was legal from the status we had, but the order no longer had that status
 * when we wrote it: another request changed it first.
 */
class OrderTransitionConflict extends RuntimeException
{
    public function __construct(
        public readonly int $orderId,
        public readonly OrderStatus $from,
        public readonly OrderStatus $to,
    ) {
        parent::__construct("Order {$orderId} was no longer {$from->value} when moving it to {$to->value}.");
    }
}
