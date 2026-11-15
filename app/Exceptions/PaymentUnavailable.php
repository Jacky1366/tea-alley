<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The payment provider couldn't start a payment right now (Stripe unreachable, an API
 * error). The order is unchanged and stays pending, so the customer can retry.
 */
class PaymentUnavailable extends RuntimeException
{
    public function __construct(public readonly int $orderId, ?Throwable $previous = null)
    {
        parent::__construct("Couldn't start a payment for order {$orderId}.", 0, $previous);
    }
}
