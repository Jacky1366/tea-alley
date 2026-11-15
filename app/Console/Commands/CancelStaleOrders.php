<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Exceptions\OrderTransitionConflict;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Backup for Stripe's checkout.session.expired webhook: cancels orders still waiting for
 * payment well after they could have been paid. Scheduled every 5 minutes (routes/console.php).
 *
 * "Well after" is GRACE_MINUTES past payment_expires_at, the expiry of the order's current
 * Checkout Session. A retry with a new Session moves it, so an order being paid right now
 * is never cancelled under the customer. An order with no Session (starting the payment
 * failed) uses its creation time plus a Session's lifetime instead.
 */
class CancelStaleOrders extends Command
{
    /** How long past the Session's expiry before cancelling: room for a late webhook. */
    public const GRACE_MINUTES = 5;

    /** An order with no Session gets the lifetime one would have had. */
    public const NO_SESSION_MINUTES = 30;

    protected $signature = 'orders:cancel-stale';

    protected $description = 'Cancel orders whose payment window has passed';

    public function handle(): int
    {
        $cutoff = now()->subMinutes(self::GRACE_MINUTES);
        $cancelled = 0;

        Order::query()
            ->where('status', OrderStatus::PendingPayment)
            ->where(fn (Builder $query) => $query
                ->where('payment_expires_at', '<', $cutoff)
                ->orWhere(fn (Builder $noSession) => $noSession
                    ->whereNull('payment_expires_at')
                    ->where('created_at', '<', $cutoff->subMinutes(self::NO_SESSION_MINUTES))))
            // By id range, not offset: each cancellation drops the order out of this query.
            ->lazyById()
            ->each(function (Order $order) use (&$cancelled): void {
                try {
                    $order->transitionTo(OrderStatus::Cancelled);
                    $cancelled++;
                } catch (InvalidOrderTransition|OrderTransitionConflict) {
                    // Paid (or cancelled by the webhook) since it was read: leave it.
                }
            });

        $this->info("Cancelled {$cancelled} stale ".str('order')->plural($cancelled).'.');

        return self::SUCCESS;
    }
}
