<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Customers see only their own orders. Someone else's order is denied as a 404, the
 * same response as an order number that doesn't exist, so receipts can't be used to
 * find out which order numbers are in use.
 */
class OrderPolicy
{
    public function view(User $user, Order $order): Response
    {
        return $order->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
