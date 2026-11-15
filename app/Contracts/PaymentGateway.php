<?php

namespace App\Contracts;

use App\Exceptions\InvalidOrderTransition;
use App\Exceptions\OrderTransitionConflict;
use App\Exceptions\PaymentUnavailable;
use App\Models\Order;

interface PaymentGateway
{
    /**
     * Start paying for a pending order and return the URL to send the customer to.
     * Calling it for an order that is already paid must not charge again.
     *
     * @throws InvalidOrderTransition the order can't be paid any more (it was cancelled)
     * @throws OrderTransitionConflict another request moved the order to something other than paid
     * @throws PaymentUnavailable the provider couldn't start a payment; the order stays pending
     */
    public function startPayment(Order $order): string;
}
