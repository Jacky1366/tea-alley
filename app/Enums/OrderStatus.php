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
