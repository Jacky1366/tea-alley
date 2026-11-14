<?php

namespace App\Listeners;

use App\Enums\Locale;
use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Notifications\OrderReadyNotification;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Throwable;

/**
 * Emails the customer when their order first becomes ready.
 *
 * Once per order: a Recall always increments recalled_count, so "ready with
 * recalled_count 0" happens only on the first Bump. The order is re-read here rather
 * than trusted from the caller, so a stale model can't cause a second email.
 *
 * Never fails the transition: it runs synchronously (there is no queue worker), and any
 * exception, from loading the order to the mail transport, is reported (logged) and
 * swallowed. Order::transitionTo() has already written the row by the time this runs.
 */
class SendOrderReadyNotification
{
    public function handle(OrderStatusChanged $event): void
    {
        if ($event->status !== OrderStatus::Ready) {
            return;
        }

        try {
            $order = Order::query()
                ->with([
                    'user',
                    // OrderItem::modifierSummary() relies on modifiers being in insert order.
                    'items' => fn (HasMany $query) => $query->orderBy('id'),
                    'items.modifiers' => fn (HasMany $query) => $query->orderBy('id'),
                ])
                ->find($event->orderId);

            // Ready again after a Recall: the customer was told the first time.
            if ($order === null || $order->recalled_count > 0) {
                return;
            }

            $order->user->notify(
                (new OrderReadyNotification($order))->locale(Locale::fromValue($order->user->locale)->value),
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
