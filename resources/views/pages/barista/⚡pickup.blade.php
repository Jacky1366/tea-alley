<?php

use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Exceptions\OrderTransitionConflict;
use App\Models\Order;
use App\Support\LocalTime;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * T3-1: where a customer's pickup QR leads (route barista.pickup: staff only, signed link).
 *
 * Opening the page never changes the order: phones and camera apps may open a link ahead of
 * time. "Confirm pickup" does, the same way the board's Picked up button does: it re-reads the
 * order, checks OrderPolicy::advance, requires Ready, and goes through Order::transitionTo(),
 * which tells the customer's page and the board (OrderStatusChanged). If someone else confirmed
 * first, this page says so instead of failing.
 */
new #[Layout('layouts::app')] #[Title('Confirm pickup')] class extends Component {
    #[Locked]
    public Order $order;

    /**
     * What "Confirm pickup" just did: "" (nothing yet), "picked-up", or "already" (someone else
     * moved the order first). Display only.
     */
    #[Locked]
    public string $outcome = '';

    public function mount(Order $order): void
    {
        $this->authorize('viewBoard', Order::class);

        $this->order = $order;
    }

    /**
     * Before every render: Livewire re-queries a model property without its relations on each
     * request, so load what the page shows here (#41). Modifiers in insert order for
     * OrderItem::modifierSummary().
     */
    public function rendering(): void
    {
        $this->order->load([
            'user',
            'items' => fn (HasMany $query) => $query->orderBy('id'),
            'items.modifiers' => fn (HasMany $query) => $query->orderBy('id'),
        ]);
    }

    public function confirm(): void
    {
        $order = Order::query()->findOrFail($this->order->id);

        $this->authorize('advance', [$order, OrderStatus::PickedUp]);

        if ($order->status !== OrderStatus::Ready) {
            $this->order = $order;
            $this->outcome = $order->status === OrderStatus::PickedUp ? 'already' : '';

            return;
        }

        try {
            $order->transitionTo(OrderStatus::PickedUp);
            $this->outcome = 'picked-up';
        } catch (InvalidOrderTransition|OrderTransitionConflict) {
            $this->outcome = 'already';
        }

        $this->order = $order->refresh();
    }

    public function drinkCount(): int
    {
        return intval($this->order->items->sum('quantity'));
    }

    public function time(?CarbonInterface $time): string
    {
        return $time === null ? '' : LocalTime::format($time, 'time');
    }
}; ?>

<div class="mx-auto flex w-full max-w-xl flex-col gap-6" data-test="pickup-page">
    <div class="flex flex-col gap-1">
        <flux:text>{{ __('Order number') }}</flux:text>
        <flux:heading size="xl" level="1" class="text-4xl! tracking-wide" data-test="order-number">{{ $order->order_number }}</flux:heading>
        <div class="mt-1 flex flex-wrap items-center gap-2">
            <flux:badge size="sm" :color="$order->status === OrderStatus::Ready ? 'green' : 'zinc'" data-test="order-status">{{ $order->status->label() }}</flux:badge>
            <flux:text size="sm" data-test="customer">{{ $order->user->name }}</flux:text>
            <flux:text size="sm">· {{ trans_choice(':count drink|:count drinks', $this->drinkCount(), ['count' => $this->drinkCount()]) }}</flux:text>
        </div>
    </div>

    <ul class="flex flex-col divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="pickup-lines">
        @foreach ($order->items as $item)
            <li class="p-4" wire:key="item-{{ $item->id }}" data-test="pickup-line">
                <flux:heading>{{ __(':drink × :quantity', ['drink' => $item->localizedName(), 'quantity' => $item->quantity]) }}</flux:heading>
                @foreach ($item->modifierSummary() as $group)
                    <flux:text size="sm" class="mt-1">{{ $group }}</flux:text>
                @endforeach
            </li>
        @endforeach
    </ul>

    @if ($outcome === 'picked-up')
        <flux:callout variant="success" icon="check-circle" data-test="pickup-done">
            <flux:callout.heading>{{ __('Picked up') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Hand the drinks over. The customer\'s page and the board are updated.') }}</flux:callout.text>
        </flux:callout>
    @elseif ($outcome === 'already' || $order->status === OrderStatus::PickedUp)
        <flux:callout variant="warning" icon="exclamation-triangle" data-test="pickup-already">
            <flux:callout.text>{{ __('This order was already picked up at :time.', ['time' => $this->time($order->picked_up_at)]) }}</flux:callout.text>
        </flux:callout>
    @elseif ($order->status === OrderStatus::Ready)
        <flux:button variant="primary" class="h-14! w-full text-lg" wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm" data-test="confirm-pickup">
            {{ __('Confirm pickup') }}
        </flux:button>
    @else
        <flux:callout variant="warning" icon="exclamation-triangle" data-test="pickup-not-ready">
            <flux:callout.text>
                @if ($order->status === OrderStatus::Cancelled)
                    {{ __('This order was cancelled.') }}
                @elseif ($order->status === OrderStatus::PendingPayment)
                    {{ __('This order hasn\'t been paid.') }}
                @else
                    {{ __('This order isn\'t ready yet. Bump it on the board when the drinks are done.') }}
                @endif
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="flex flex-wrap gap-2">
        <flux:button :href="route('barista.scan')" icon="qr-code" data-test="scan-next">{{ __('Scan next') }}</flux:button>
        <flux:button :href="route('barista')" variant="ghost" wire:navigate>{{ __('Barista board') }}</flux:button>
    </div>
</div>
