<?php

namespace App\Services\Payments;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Exceptions\OrderTransitionConflict;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Event;

/**
 * Applies a Stripe event whose signature has already been verified. It never throws for an
 * event it can't apply: the controller must answer 200, or Stripe resends it for days.
 * Anything worth a human's attention is logged instead (ids only, no customer data).
 *
 * The amount is the source of truth for "paid": the order is only marked paid when Stripe's
 * amount_total and currency equal the order's stored total (calculated by PricingService
 * when the order was placed). The current menu is not consulted: a drink sold out after
 * the order was placed doesn't stop it being paid (docs/decisions.md).
 */
final class StripeWebhookHandler
{
    public function handle(Event $event): void
    {
        $session = $event->data->object ?? null;

        if (! $session instanceof Session) {
            return;  // not a Checkout event; nothing to do
        }

        match ($event->type) {
            // Paid by card: "completed" with payment_status "paid". A delayed method (enabled in
            // the Dashboard) completes "unpaid" and is paid later by async_payment_succeeded.
            Event::CHECKOUT_SESSION_COMPLETED,
            Event::CHECKOUT_SESSION_ASYNC_PAYMENT_SUCCEEDED => $this->paid($session),
            Event::CHECKOUT_SESSION_EXPIRED => $this->expired($session),
            // async_payment_failed: the order stays pending; orders:cancel-stale cancels it once
            // its Session's window has passed.
            default => null,
        };
    }

    private function paid(Session $session): void
    {
        $order = $this->orderFor($session);

        if ($order === null) {
            return;
        }

        if ($session->payment_status !== Session::PAYMENT_STATUS_PAID) {
            // Normal for a delayed payment method: async_payment_succeeded follows.
            Log::info('Stripe session completed; the payment has not arrived yet.', [
                'order_id' => $order->id,
                'session_id' => $session->id,
                'payment_status' => $session->payment_status,
            ]);

            return;
        }

        if ($session->amount_total !== $order->total_cents || $session->currency !== StripePaymentGateway::CURRENCY) {
            Log::error('Stripe payment amount does not match the order; not marking it paid.', [
                'order_id' => $order->id,
                'session_id' => $session->id,
                'order_total_cents' => $order->total_cents,
                'stripe_amount_total' => $session->amount_total,
                'stripe_currency' => $session->currency,
            ]);

            return;
        }

        if ($order->status->isPaid()) {
            return;  // a resent event: already applied
        }

        try {
            $order->transitionTo(OrderStatus::Paid);
        } catch (InvalidOrderTransition|OrderTransitionConflict) {
            // Moved on between reading and writing. Paid by now is fine (a parallel delivery of
            // this event); anything else means money was taken for an order we can't fulfil.
            if (! $order->refresh()->status->isPaid()) {
                Log::error('Stripe payment received for an order that can no longer be paid; it needs a refund.', [
                    'order_id' => $order->id,
                    'session_id' => $session->id,
                    'order_status' => $order->status->value,
                ]);
            }
        }
    }

    private function expired(Session $session): void
    {
        $order = $this->orderFor($session);

        // Only a pending order is cancelled. Paid or later stays as it is.
        if ($order === null || $order->status !== OrderStatus::PendingPayment) {
            return;
        }

        try {
            $order->transitionTo(OrderStatus::Cancelled);
        } catch (InvalidOrderTransition|OrderTransitionConflict) {
            // Paid or cancelled by another request in the meantime: nothing to do.
        }
    }

    /**
     * The order this Session belongs to: found by its current stripe_session_id AND
     * matching metadata.order_id. An older Session (replaced by a retry) finds nothing, so
     * its late "expired" event can't cancel an order that is being paid with a newer one.
     */
    private function orderFor(Session $session): ?Order
    {
        $order = Order::query()->where('stripe_session_id', $session->id)->first();
        $metadataOrderId = $session->metadata['order_id'] ?? null;

        if ($order === null) {
            $this->warn('Stripe session does not belong to any current order', $session);

            return null;
        }

        if ($metadataOrderId !== strval($order->id)) {
            $this->warn('Stripe session metadata names a different order', $session, $order);

            return null;
        }

        return $order;
    }

    private function warn(string $message, Session $session, ?Order $order = null): void
    {
        Log::warning($message.'; ignored.', [
            'order_id' => $order?->id,
            'session_id' => $session->id,
            'metadata_order_id' => $session->metadata['order_id'] ?? null,
            'payment_status' => $session->payment_status,
        ]);
    }
}
