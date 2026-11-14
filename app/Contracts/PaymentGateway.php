<?php

namespace App\Contracts;

use App\Models\Order;

interface PaymentGateway
{
    /**
     * Start paying for a pending order and return the URL to send the customer to.
     * Calling it for an order that is already paid must not charge again.
     */
    public function startPayment(Order $order): string;
}
