<?php

use App\Enums\OrderStatus;
use App\Enums\PickupType;
use App\Models\Order;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The receipt. The route's can:view,order middleware (OrderPolicy) has already checked
 * ownership. Everything shown comes from the order's snapshot columns: menu_items and
 * modifier_options are never read, so later menu changes can't alter a receipt.
 */
new #[Layout('layouts::public')] #[Title('Receipt')] class extends Component {
    #[Locked]
    public Order $order;

    public function mount(Order $order): void
    {
        $this->order = $order->load([
            'items' => fn (HasMany $query) => $query->orderBy('id'),
            'items.modifiers' => fn (HasMany $query) => $query->orderBy('id'),
        ]);
    }

    public function money(int $cents): string
    {
        return Money::format($cents);
    }

    public function local(CarbonInterface $time): CarbonInterface
    {
        return $time->copy()->setTimezone(config()->string('app.display_timezone'));
    }
}; ?>

<div class="mx-auto flex w-full max-w-2xl flex-col gap-6">
    <div class="flex flex-col gap-1">
        <flux:text>{{ __('Order number') }} 訂單號碼</flux:text>
        <flux:heading size="xl" level="1" class="text-4xl! tracking-wide" data-test="order-number">{{ $order->order_number }}</flux:heading>
        <div class="mt-1 flex flex-wrap items-center gap-2">
            <flux:badge
                size="sm"
                :color="$order->status === OrderStatus::PendingPayment ? 'amber' : ($order->status === OrderStatus::Cancelled ? 'zinc' : 'green')"
                data-test="order-status"
            >{{ $order->status->label() }}</flux:badge>
            <flux:text size="sm" data-test="placed-at">
                {{ __('Placed :time', ['time' => $this->local($order->created_at)->format('M j, Y g:i A')]) }}
            </flux:text>
        </div>
    </div>

    <flux:callout icon="clock" data-test="pickup">
        <flux:callout.heading>{{ __('Pickup') }} 取餐</flux:callout.heading>
        <flux:callout.text>
            @if ($order->pickup_at === null)
                {{ __('As soon as possible') }}
            @elseif ($order->pickup_type === PickupType::Asap)
                {{ __('As soon as possible, around :time', ['time' => $this->local($order->pickup_at)->format('g:i A')]) }}
            @else
                {{ __('Scheduled for :time', ['time' => $this->local($order->pickup_at)->format('D, M j g:i A')]) }}
            @endif
        </flux:callout.text>
    </flux:callout>

    <ul class="flex flex-col divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="receipt-lines">
        @foreach ($order->items as $item)
            <li class="flex items-start justify-between gap-3 p-4" wire:key="item-{{ $item->id }}" data-test="receipt-line">
                <div class="min-w-0">
                    <flux:heading>{{ $item->name_en }} × {{ $item->quantity }}</flux:heading>
                    <flux:text>{{ $item->name_zh }}</flux:text>
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
    <dl class="flex flex-col gap-1" data-test="totals">
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

    <flux:link :href="route('orders.index')" wire:navigate>{{ __('All my orders') }}</flux:link>
</div>
