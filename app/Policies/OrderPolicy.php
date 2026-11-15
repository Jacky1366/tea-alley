<?php

namespace App\Policies;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class OrderPolicy
{
    /**
     * The statuses staff may move an order to from the barista board: Start and Recall
     * (preparing), Bump (ready), Picked up. Never paid or cancelled; those belong to the
     * payment gateway and the T2-5 scheduler. Whether the move is legal from the order's
     * current status is the state machine's job (Order::transitionTo).
     */
    public const STAFF_TARGETS = [OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::PickedUp];

    /**
     * Customers see only their own orders. Someone else's order is denied as a 404, the
     * same response as an order number that doesn't exist, so receipts can't be used to
     * find out which order numbers are in use.
     */
    public function view(User $user, Order $order): Response
    {
        return $order->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Retry paying for an order: only its customer, and only while it's waiting for payment.
     * Someone else's order is a 404, as in view().
     */
    public function pay(User $user, Order $order): Response
    {
        if ($order->user_id !== $user->id) {
            return Response::denyAsNotFound();
        }

        return $order->status === OrderStatus::PendingPayment
            ? Response::allow()
            : Response::deny(__('This order can no longer be paid.'));
    }

    /**
     * The demo's "Simulate payment" button: local environment only, an admin, and only on
     * their own pending order (an admin can't open anyone else's receipt anyway).
     */
    public function simulatePayment(User $user, Order $order): bool
    {
        return app()->environment('local')
            && $user->hasRole('admin')
            && $order->user_id === $user->id
            && $order->status === OrderStatus::PendingPayment;
    }

    /**
     * Open the barista board.
     */
    public function viewBoard(User $user): bool
    {
        return $user->canUseBaristaBoard();
    }

    /**
     * Move an order to $to from the barista board.
     */
    public function advance(User $user, Order $order, OrderStatus $to): bool
    {
        return $user->canUseBaristaBoard() && in_array($to, self::STAFF_TARGETS, true);
    }
}
