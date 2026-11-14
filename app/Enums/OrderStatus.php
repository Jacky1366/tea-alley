<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case PickedUp = 'picked_up';
    case Cancelled = 'cancelled';

    /**
     * The order state machine. This is the only place the legal transitions are listed;
     * Order::transitionTo() refuses everything else. Moving to the same status is never
     * legal, and picked_up and cancelled are final.
     */
    public function canTransitionTo(self $to): bool
    {
        $allowed = match ($this) {
            self::PendingPayment => [self::Paid, self::Cancelled],  // payment gateway / T2-5 scheduler
            self::Paid => [self::Preparing],                        // staff: Start
            self::Preparing => [self::Ready],                       // staff: Bump
            self::Ready => [self::Preparing, self::PickedUp],       // staff: Recall / Picked up
            self::PickedUp, self::Cancelled => [],
        };

        return in_array($to, $allowed, true);
    }

    /**
     * Whether the order has been paid for: paid, or any later step of making it.
     */
    public function isPaid(): bool
    {
        return in_array($this, [self::Paid, self::Preparing, self::Ready, self::PickedUp], true);
    }

    /**
     * What the customer sees on the receipt and in order history.
     */
    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => __('Payment pending'),
            self::Paid => __('Paid'),
            self::Preparing => __('Preparing'),
            self::Ready => __('Ready'),
            self::PickedUp => __('Picked up'),
            self::Cancelled => __('Cancelled'),
        };
    }
}
