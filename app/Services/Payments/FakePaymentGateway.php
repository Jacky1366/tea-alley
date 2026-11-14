<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Models\Order;

/**
 * Marks the order paid immediately. Bound in every environment until Stripe replaces
 * it in T2-5; the app isn't deployed before then.
 */
final class FakePaymentGateway implements PaymentGateway
{
    public function startPayment(Order $order): string
    {
        // Only a pending order is marked paid; the condition makes a repeated call a no-op.
        Order::query()
            ->whereKey($order->id)
            ->where('status', OrderStatus::PendingPayment)
            ->update(['status' => OrderStatus::Paid, 'paid_at' => now()]);

        return route('orders.show', $order);
    }
}
