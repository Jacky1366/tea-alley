<?php

use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Enums\PickupType;
use App\Exceptions\InvalidOrderTransition;
use App\Exceptions\OrderTransitionConflict;
use App\Exceptions\PaymentUnavailable;
use App\Models\Order;
use App\Models\User;
use App\Support\ActionLimiter;
use App\Support\LocalTime;
use App\Support\Money;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The receipt, with the order's live status above it. The route's can:view,order
 * middleware (OrderPolicy) has already checked ownership. Everything shown comes from the
 * order's snapshot columns: menu_items and modifier_options are never read, so later menu
 * changes can't alter a receipt.
 *
 * Re-rendered when an OrderStatusChanged event arrives on the private orders.{id} channel,
 * and polled as a fallback (<x-realtime-status>) until the order is picked up or cancelled.
 */
new #[Layout('layouts::public')] #[Title('Receipt')] class extends Component {
    #[Locked]
    public Order $order;

    /**
     * Where the customer came back from Stripe: "success", "cancelled", or "" (any other
     * visit). Display only: it picks the wording for a pending order, nothing more.
     */
    #[Locked]
    public string $checkoutReturn = '';

    /**
     * Why paying didn't start (from checkout's flash, or from Retry / Simulate payment on this
     * page). A property rather than a flash: the page polls, and a flash is gone by the
     * second refresh.
     */
    #[Locked]
    public string $paymentMessage = '';

    public function mount(Order $order): void
    {
        $flashed = session('payment-status');
        $this->paymentMessage = is_string($flashed) ? $flashed : '';

        $return = request()->query('checkout');
        $this->checkoutReturn = in_array($return, ['success', 'cancelled'], true) ? $return : '';

        $this->order = $order;
    }

    /**
     * Load what the receipt shows before every render, not only on mount: Livewire keeps a
     * model property as its class and key and re-queries it without relations on each
     * request ($refresh, a status event, an action), so a load in mount() is gone by the
     * first update (#41). Ordered by id because OrderItem::modifierSummary() relies on
     * modifiers being in insert order; this also replaces the unordered reload that
     * refresh() does in the actions.
     */
    public function rendering(): void
    {
        $this->order->load([
            'items' => fn (HasMany $query) => $query->orderBy('id'),
            'items.modifiers' => fn (HasMany $query) => $query->orderBy('id'),
        ]);
    }

    /** The progress bar's steps, in order. Recall moves the order back to Preparing. */
    public const PROGRESS = [OrderStatus::Paid, OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::PickedUp];

    /**
     * The order changed status. The payload isn't used: $order is read again from the
     * database on every request.
     */
    #[On('echo-private:orders.{order.id},OrderStatusChanged')]
    public function orderStatusChanged(): void {}

    /** The step label: the first one is "Received" rather than "Paid". */
    public function stepLabel(OrderStatus $step): string
    {
        return $step === OrderStatus::Paid ? __('Received') : $step->label();
    }

    /**
     * Pay again for this pending order: reuses the open Stripe Session, or starts a new one.
     * Same order, never a new one. Only its customer, only while it's pending (OrderPolicy::pay).
     */
    public function retryPayment(): void
    {
        $order = $this->order->refresh();

        if (! $this->canPay($order)) {
            return;
        }

        // T3-3: per user. Each retry may call Stripe with the order row locked.
        $user = auth()->user();

        if ($user instanceof User && ($wait = ActionLimiter::attempt(ActionLimiter::RETRY_PAYMENT, $user)) !== null) {
            $this->paymentMessage = ActionLimiter::message($wait);

            return;
        }

        try {
            $this->redirect(app(PaymentGateway::class)->startPayment($order));
        } catch (PaymentUnavailable) {
            $this->paymentMessage = __("We couldn't start the payment. Please try again.");
        } catch (InvalidOrderTransition|OrderTransitionConflict) {
            $this->paymentMessage = __('This order can no longer be paid.');
        }
    }

    /**
     * Demo backup: mark the admin's own pending order paid without Stripe. Local environment
     * only (OrderPolicy::simulatePayment); goes through the state machine like the webhook.
     */
    public function simulatePayment(): void
    {
        $order = $this->order->refresh();

        $this->authorize('simulatePayment', $order);

        try {
            $order->transitionTo(OrderStatus::Paid);
            $this->paymentMessage = '';
        } catch (InvalidOrderTransition|OrderTransitionConflict) {
            $this->paymentMessage = __('This order can no longer be paid.');
        }
    }

    /**
     * Someone else's order is a 404; an order that isn't pending any more gets a message
     * (it was probably paid or cancelled while the page was open), not an error page.
     */
    private function canPay(Order $order): bool
    {
        $response = Gate::inspect('pay', $order);

        if ($response->allowed()) {
            return true;
        }

        if ($response->status() === 404) {
            abort(404);
        }

        $this->paymentMessage = $response->message() ?? __('This order can no longer be paid.');

        return false;
    }

    /** Whether the status can still change, so it's worth listening and polling. */
    public function isOpen(): bool
    {
        return ! in_array($this->order->status, [OrderStatus::PickedUp, OrderStatus::Cancelled], true);
    }

    public function money(int $cents): string
    {
        return Money::format($cents);
    }
}; ?>

<div class="mx-auto flex w-full max-w-2xl flex-col gap-6">
    <div class="flex flex-col gap-1">
        <flux:text>{{ __('Order number') }}</flux:text>
        <flux:heading size="xl" level="1" class="text-4xl! tracking-wide" data-test="order-number">{{ $order->order_number }}</flux:heading>
        <div class="mt-1 flex flex-wrap items-center gap-2">
            <flux:badge
                size="sm"
                :color="$order->status === OrderStatus::PendingPayment ? 'amber' : ($order->status === OrderStatus::Cancelled ? 'zinc' : 'green')"
                data-test="order-status"
            >{{ $order->status->label() }}</flux:badge>
            <flux:text size="sm" data-test="placed-at">
                {{ __('Placed :time', ['time' => LocalTime::format($order->created_at, 'date_time')]) }}
            </flux:text>
        </div>
    </div>

    <section class="flex flex-col gap-3 rounded-2xl border border-line bg-card p-4 shadow-warm" data-test="order-progress-section">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="lg" level="2">{{ __('Order status') }}</flux:heading>
            @if ($this->isOpen())
                <x-realtime-status :channel="'private-orders.'.$order->id" />
            @endif
        </div>

        {{-- Hidden once the order is paid: the message no longer applies. --}}
        @if ($paymentMessage !== '' && in_array($order->status, [OrderStatus::PendingPayment, OrderStatus::Cancelled], true))
            <flux:callout variant="warning" icon="exclamation-triangle" data-test="payment-status">
                <flux:callout.text>{{ $paymentMessage }}</flux:callout.text>
            </flux:callout>
        @endif

        @if ($order->status === OrderStatus::PendingPayment)
            @if ($checkoutReturn === 'success')
                {{-- Back from Stripe after paying: the webhook marks it paid, and the page updates itself. --}}
                <flux:text data-test="status-message">{{ __('Confirming your payment…') }}</flux:text>
            @else
                <div class="flex flex-col items-start gap-3" data-test="payment-incomplete">
                    <flux:text data-test="status-message">{{ __('Payment not completed.') }}</flux:text>
                    <flux:button variant="primary" wire:click="retryPayment" wire:loading.attr="disabled" wire:target="retryPayment" data-test="retry-payment">
                        {{ __('Retry payment') }}
                    </flux:button>
                </div>
            @endif

            @can('simulatePayment', $order)
                <flux:button size="sm" wire:click="simulatePayment" class="self-start" data-test="simulate-payment">
                    {{ __('Simulate payment') }}
                </flux:button>
            @endcan
        @elseif ($order->status === OrderStatus::Cancelled)
            <flux:text data-test="status-message">{{ $order->status->label() }}</flux:text>
        @else
            @php($current = array_search($order->status, self::PROGRESS, true))
            <ol class="grid grid-cols-4 gap-2" data-test="order-progress">
                @foreach (self::PROGRESS as $i => $step)
                    <li
                        class="flex flex-col gap-1.5"
                        data-test="progress-step"
                        data-step="{{ $step->value }}"
                        data-state="{{ $i < $current ? 'done' : ($i === $current ? 'current' : 'todo') }}"
                        @if ($i === $current) aria-current="step" @endif
                    >
                        <span @class([
                            'h-1.5 rounded-full',
                            'bg-matcha' => $i <= $current,
                            'bg-line' => $i > $current,
                        ])></span>
                        <span @class([
                            'text-sm',
                            'font-semibold text-ink' => $i === $current,
                            'text-ink-muted' => $i !== $current,
                        ])>{{ $this->stepLabel($step) }}</span>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>

    <flux:callout icon="clock" data-test="pickup">
        <flux:callout.heading>{{ __('Pickup') }}</flux:callout.heading>
        <flux:callout.text>
            @if ($order->pickup_at === null)
                {{ __('As soon as possible') }}
            @elseif ($order->pickup_type === PickupType::Asap)
                {{ __('As soon as possible, around :time', ['time' => LocalTime::format($order->pickup_at, 'time')]) }}
            @else
                {{ __('Scheduled for :time', ['time' => LocalTime::format($order->pickup_at, 'day_date_time')]) }}
            @endif
        </flux:callout.text>
    </flux:callout>

    <div class="overflow-hidden rounded-2xl border border-line bg-card shadow-warm">
        <ul class="flex flex-col divide-y divide-line" data-test="receipt-lines">
            @foreach ($order->items as $item)
                <li class="flex items-start justify-between gap-3 p-4" wire:key="item-{{ $item->id }}" data-test="receipt-line">
                    <div class="min-w-0">
                        <flux:heading>{{ __(':drink × :quantity', ['drink' => $item->localizedName(), 'quantity' => $item->quantity]) }}</flux:heading>
                        @foreach ($item->modifierSummary() as $group)
                            <flux:text size="sm" class="mt-1" data-test="modifier-group">{{ $group }}</flux:text>
                        @endforeach
                        <flux:text size="sm" class="mt-1">{{ __(':price each', ['price' => $this->money($item->unit_price_cents)]) }}</flux:text>
                    </div>
                    <flux:heading class="shrink-0" data-test="line-total">{{ $this->money($item->displayLineTotalCents()) }}</flux:heading>
                </li>
            @endforeach
        </ul>

        {{-- Every amount below is an orders column, calculated by PricingService when the order was placed. --}}
        <dl class="flex flex-col gap-1 border-t border-line bg-zinc-50 p-4" data-test="totals">
            <div class="flex justify-between">
                <dt><flux:text>{{ __('Subtotal') }}</flux:text></dt>
                <dd><flux:text data-test="subtotal">{{ $this->money($order->subtotal_cents) }}</flux:text></dd>
            </div>
            @if ($order->discount_cents > 0)
                <div class="flex justify-between">
                    <dt><flux:text>{{ $order->promo_code ? __('Discount (:code)', ['code' => $order->promo_code]) : __('Discount') }}</flux:text></dt>
                    <dd><flux:text data-test="discount">−{{ $this->money($order->discount_cents) }}</flux:text></dd>
                </div>
            @endif
            <div class="flex justify-between">
                <dt><flux:text>{{ __('GST') }}</flux:text></dt>
                <dd><flux:text data-test="gst">{{ $this->money($order->gst_cents) }}</flux:text></dd>
            </div>
            <div class="flex justify-between">
                <dt><flux:text>{{ __('PST') }}</flux:text></dt>
                <dd><flux:text data-test="pst">{{ $this->money($order->pst_cents) }}</flux:text></dd>
            </div>
            <div class="mt-1 flex items-baseline justify-between">
                <dt><flux:heading size="lg">{{ __('Total') }}</flux:heading></dt>
                <dd><flux:heading size="lg" data-test="total">{{ $this->money($order->total_cents) }}</flux:heading></dd>
            </div>
        </dl>
    </div>

    <flux:link :href="route('orders.index')" wire:navigate>{{ __('All my orders') }}</flux:link>
</div>
