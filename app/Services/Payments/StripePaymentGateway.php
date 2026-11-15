<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Enums\Locale;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Exceptions\PaymentUnavailable;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Localizable;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Pays through a hosted Stripe Checkout Session. This only sends the customer to Stripe:
 * the order is marked paid by the signed webhook (StripeWebhookHandler), never here and
 * never by the success page.
 *
 * One open Session per order. A retry reuses the current Session while Stripe still has
 * it open, and creates a new one only once it has expired (or there is none, when an
 * earlier attempt failed). The decision runs with the order row locked, so a double-click
 * waits for the first request and then reuses the Session it created.
 */
final class StripePaymentGateway implements PaymentGateway
{
    use Localizable;

    /**
     * Stripe accepts 30 minutes to 24 hours after the Session is created. 30 computed here
     * can arrive a moment short of that and be rejected, so 31.
     */
    public const SESSION_MINUTES = 31;

    public const CURRENCY = 'cad';

    /**
     * Limits for each call to Stripe, made while the order row is locked (AppServiceProvider
     * applies them): connecting, the whole request including connecting, and how many
     * times the SDK retries a failed request.
     */
    public const CONNECT_TIMEOUT_SECONDS = 5;

    public const TIMEOUT_SECONDS = 10;

    public const NETWORK_RETRIES = 1;

    public function __construct(private readonly StripeClient $stripe) {}

    public function startPayment(Order $order): string
    {
        if ($order->status->isPaid()) {
            return route('orders.show', $order);
        }

        if ($order->status !== OrderStatus::PendingPayment) {
            throw new InvalidOrderTransition($order->id, $order->status, OrderStatus::Paid);
        }

        try {
            return DB::transaction(fn (): string => $this->checkoutUrl($order));
        } catch (ApiErrorException $e) {
            // Safe fields only. Never the message: Stripe's "Invalid API Key provided" message
            // includes part of the key, and keys must never reach the logs.
            Log::warning('Stripe API call failed; the order stays pending.', [
                'order_id' => $order->id,
                'error' => class_basename($e),
                'http_status' => $e->getHttpStatus(),
                'stripe_error_type' => $e->getError()?->type,
                'stripe_error_code' => $e->getStripeCode(),
                'stripe_error_param' => $e->getError()?->param,
                'stripe_request_id' => $e->getRequestId(),
            ]);

            throw new PaymentUnavailable($order->id, $e);
        }
    }

    /**
     * Inside the transaction: lock the order, then reuse or create its Session.
     *
     * @throws ApiErrorException
     */
    private function checkoutUrl(Order $order): string
    {
        $locked = Order::query()->whereKey($order->id)->lockForUpdate()->sole();

        // Paid or cancelled while this request waited for the lock.
        if ($locked->status->isPaid()) {
            return route('orders.show', $locked);
        }

        if ($locked->status !== OrderStatus::PendingPayment) {
            throw new InvalidOrderTransition($locked->id, $locked->status, OrderStatus::Paid);
        }

        if ($locked->stripe_session_id !== null) {
            $current = $this->stripe->checkout->sessions->retrieve($locked->stripe_session_id);

            if ($current->status === Session::STATUS_OPEN && is_string($current->url)) {
                return $current->url;
            }

            // Paid; the webhook marks the order paid. The receipt shows "Confirming payment".
            if ($current->status === Session::STATUS_COMPLETE) {
                return $locked->receiptUrl(['checkout' => 'success']);
            }
        }

        $session = $this->stripe->checkout->sessions->create($this->sessionParams($locked));

        $locked->forceFill([
            'stripe_session_id' => $session->id,
            'payment_expires_at' => CarbonImmutable::createFromTimestamp($session->expires_at),
        ])->save();

        if (! is_string($session->url)) {
            throw new PaymentUnavailable($locked->id);
        }

        return $session->url;
    }

    /**
     * One line item for the whole order: the amount is the total PricingService stored on
     * the order, never recalculated. The drinks are listed in the description.
     *
     * @return array<string, mixed>
     */
    private function sessionParams(Order $order): array
    {
        $order->load([
            'user',
            'items' => fn (HasMany $query) => $query->orderBy('id'),
        ]);

        // The Stripe page, the line item name, and the drink names in the customer's language.
        $locale = Locale::fromValue($order->user->locale);

        [$name, $drinks] = $this->withLocale($locale->value, fn (): array => [
            __('Tea Alley order :number', ['number' => $order->order_number]),
            $order->items->map(fn (OrderItem $item): string => $item->quantity.' × '.$item->localizedName())->implode(', '),
        ]);

        return [
            'mode' => 'payment',
            // No payment_method_types: since API version 2026-10-28 Stripe rejects it, and the
            // methods offered are set in the Dashboard (Settings > Payment methods). A delayed
            // method is paid by checkout.session.async_payment_succeeded (StripeWebhookHandler).
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => self::CURRENCY,
                    'unit_amount' => $order->total_cents,
                    'product_data' => [
                        'name' => $name,
                        'description' => Str::limit($drinks, 480),
                    ],
                ],
            ]],
            'metadata' => ['order_id' => strval($order->id)],
            'client_reference_id' => strval($order->id),
            'expires_at' => now()->addMinutes(self::SESSION_MINUTES)->getTimestamp(),
            'success_url' => $order->receiptUrl(['checkout' => 'success']),
            'cancel_url' => $order->receiptUrl(['checkout' => 'cancelled']),
            'locale' => $locale === Locale::TraditionalChinese ? 'zh-TW' : 'en',
        ];
    }
}
