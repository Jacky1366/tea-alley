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
}
