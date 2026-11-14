<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Exceptions\OrderTransitionConflict;
use App\Models\Order;

/**
 * Marks the order paid immediately. Bound in every environment until Stripe replaces
 * it in T2-5; the app isn't deployed before then.
 */
final class FakePaymentGateway implements PaymentGateway
{
    /**
     * @throws InvalidOrderTransition the order can't be paid (it was cancelled)
     * @throws OrderTransitionConflict another request moved it to something other than paid
     */
    public function startPayment(Order $order): string
    {
        // Already paid (a repeated submit): don't pay again.
        if (! $order->status->isPaid()) {
            try {
                $order->transitionTo(OrderStatus::Paid);
            } catch (OrderTransitionConflict $conflict) {
                // A simultaneous submit for the same order got there first. That's only
                // fine if it paid the order; anything else (cancelled by T2-5's
                // scheduler) is not a success.
                if (! $order->refresh()->status->isPaid()) {
                    throw $conflict;
                }
            }
        }

        return route('orders.show', $order);
    }
}
