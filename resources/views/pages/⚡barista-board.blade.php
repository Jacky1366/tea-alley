<?php

use App\Enums\OrderStatus;
use App\Enums\PickupType;
use App\Exceptions\InvalidOrderTransition;
use App\Exceptions\OrderTransitionConflict;
use App\Models\Order;
use App\Support\LocalTime;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The barista board: today's paid, preparing, and ready orders. Re-rendered when an
 * OrderStatusChanged event arrives on the private "barista" channel, and polled as a
 * fallback: every 30 seconds while live, every 5 seconds when Reverb is unreachable
 * (<x-realtime-status>).
 *
 * Nothing about the orders is a public property. The list is a computed property, queried
 * again on every render and never sent to the browser as state; actions take only an
 * order id, load the order fresh, check OrderPolicy::advance, and go through
 * Order::transitionTo().
 */
new #[Layout('layouts::app')] #[Title('Barista board')] class extends Component {
    /** The board's columns, left to right. */
    public const COLUMNS = [OrderStatus::Paid, OrderStatus::Preparing, OrderStatus::Ready];

    public function mount(): void
    {
        $this->authorize('viewBoard', Order::class);
    }

    /**
     * Today's (Vancouver date) orders in the board's columns, by pickup time. Three queries
     * however many orders there are: orders, their items, the items' modifiers.
     *
     * @return Collection<int, Order>
     */
    #[Computed]
    public function orders(): Collection
    {
        $today = now(config()->string('app.display_timezone'))->startOfDay();

        return Order::query()
            ->whereIn('status', self::COLUMNS)
            ->where('pickup_at', '>=', $today->utc())
            ->where('pickup_at', '<', $today->addDay()->utc())
            ->with([
                // OrderItem::modifierSummary() relies on modifiers being in insert order.
                'items' => fn (HasMany $query) => $query->orderBy('id'),
                'items.modifiers' => fn (HasMany $query) => $query->orderBy('id'),
            ])
            ->orderBy('pickup_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Order>
     */
    /**
     * An order changed status somewhere. The payload isn't used: the board is drawn again
     * from the database.
     */
    #[On('echo-private:barista,OrderStatusChanged')]
    public function orderStatusChanged(): void
    {
        unset($this->orders);
    }

    public function column(OrderStatus $status): Collection
    {
        return $this->orders->filter(fn (Order $order): bool => $order->status === $status)->values();
    }

    // Each button has a fixed "from" as well as a fixed "to". Start and Recall both go to
    // preparing, so without the "from" a Start clicked on a stale board could recall an
    // order someone else had already made ready.

    public function start(int $orderId): void
    {
        $this->advance($orderId, OrderStatus::Paid, OrderStatus::Preparing);
    }

    public function bump(int $orderId): void
    {
        $this->advance($orderId, OrderStatus::Preparing, OrderStatus::Ready);
    }

    public function recall(int $orderId): void
    {
        $this->advance($orderId, OrderStatus::Ready, OrderStatus::Preparing);
    }

    public function pickUp(int $orderId): void
    {
        $this->advance($orderId, OrderStatus::Ready, OrderStatus::PickedUp);
    }

    private function advance(int $orderId, OrderStatus $from, OrderStatus $to): void
    {
        $order = Order::query()->findOrFail($orderId);

        $this->authorize('advance', [$order, $to]);

        if ($order->status !== $from) {
            $this->alreadyUpdated();

            return;
        }

        try {
            $order->transitionTo($to);
        } catch (InvalidOrderTransition|OrderTransitionConflict) {
            $this->alreadyUpdated();
        }
    }

    /**
     * Someone else moved the order first. The board is drawn again from the database right
     * after this action, so the card already shows where the order is now.
     */
    private function alreadyUpdated(): void
    {
        Flux::toast(text: __('This order was already updated.'), variant: 'warning');
    }

    public function pickupTime(Order $order): string
    {
        return $order->pickup_at === null ? '' : LocalTime::format($order->pickup_at, 'time');
    }

    /**
     * Milliseconds since the epoch, for the Alpine timer. The server's clock is the
     * reference; the timer only corrects for the tablet's clock being off.
     */
    public function epochMs(?CarbonInterface $time): int
    {
        return $time?->getTimestampMs() ?? 0;
    }
}; ?>

<div
    class="flex flex-col gap-6"
    {{-- Display only: a ticking clock for the card timers. --}}
    x-data="{
        offset: {{ now()->getTimestampMs() }} - Date.now(),
        now: {{ now()->getTimestampMs() }},
        ticker: null,
        init() { this.ticker = setInterval(() => this.now = Date.now() + this.offset, 1000) },
        destroy() { clearInterval(this.ticker) },
        clock(ms) {
            const s = Math.max(0, Math.floor(ms / 1000));
            const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = String(s % 60).padStart(2, '0');
            return h > 0 ? h + ':' + String(m).padStart(2, '0') + ':' + sec : m + ':' + sec;
        },
        say(template, ms) { return template.replace(':time', this.clock(ms)) },
    }"
    data-test="barista-board"
>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading size="xl" level="1">{{ __('Barista board') }}</flux:heading>
        <x-realtime-status channel="private-barista" />
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        @foreach (self::COLUMNS as $status)
            @php($orders = $this->column($status))

            <section class="flex flex-col gap-3 rounded-xl bg-zinc-50 p-3 dark:bg-zinc-900" data-test="column-{{ $status->value }}">
                <div class="flex items-center justify-between px-1">
                    <flux:heading size="lg" level="2">{{ $status->label() }}</flux:heading>
                    <flux:badge size="sm">{{ $orders->count() }}</flux:badge>
                </div>

                @forelse ($orders as $order)
                    @php($pickupMs = $this->epochMs($order->lateAt()))
                    @php($overdueMs = $this->epochMs($order->overdueAt()))

                    <article
                        wire:key="order-{{ $order->id }}-{{ $order->status->value }}"
                        class="flex flex-col gap-3 rounded-lg border-2 border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800"
                        @if ($status !== OrderStatus::Ready)
                            x-data="{ pickup: {{ $pickupMs }}, overdue: {{ $overdueMs }} }"
                            :class="now > overdue ? 'border-red-500!' : (now > pickup ? 'border-amber-400!' : '')"
                        @endif
                        data-test="board-order"
                        data-order="{{ $order->order_number }}"
                    >
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="text-xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $order->order_number }}</span>
                            <span class="text-lg tabular-nums text-zinc-700 dark:text-zinc-200">{{ $this->pickupTime($order) }}</span>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            @if ($order->pickup_type === PickupType::Scheduled)
                                <flux:badge size="sm" color="blue" data-test="scheduled-badge">{{ __('Scheduled') }}</flux:badge>
                            @endif
                            @if ($order->recalled_count > 0)
                                <flux:badge size="sm" color="purple" data-test="recalled-badge">{{ __('Recalled ×:count', ['count' => $order->recalled_count]) }}</flux:badge>
                            @endif

                            {{-- Display only. Paid and preparing: time to pickup, then how late (yellow, then red
                                 past pickup + half the prep time). Ready: how long it has waited; no colours,
                                 since a waiting drink means the customer is late, not the bar. --}}
                            @if ($status === OrderStatus::Ready)
                                <span
                                    class="ms-auto text-sm tabular-nums text-zinc-500 dark:text-zinc-400"
                                    x-data="{ since: {{ $this->epochMs($order->ready_at) }} }"
                                    x-text="say(@js(__('Waiting :time')), now - since)"
                                    data-test="timer"
                                ></span>
                            @else
                                <span
                                    class="ms-auto text-sm font-medium tabular-nums"
                                    x-text="now <= pickup ? say(@js(__('In :time')), pickup - now) : say(@js(__(':time late')), now - pickup)"
                                    :class="now > overdue ? 'text-red-600 dark:text-red-400' : (now > pickup ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-500 dark:text-zinc-400')"
                                    data-test="timer"
                                ></span>
                            @endif
                        </div>

                        <ul class="flex flex-col gap-2">
                            @foreach ($order->items as $item)
                                <li>
                                    <div class="font-medium text-zinc-900 dark:text-white">
                                        <span class="tabular-nums">{{ $item->quantity }} ×</span> {{ $item->localizedName() }}
                                    </div>
                                    @foreach ($item->modifierSummary() as $line)
                                        <div class="text-sm text-zinc-600 dark:text-zinc-300">{{ $line }}</div>
                                    @endforeach
                                </li>
                            @endforeach
                        </ul>

                        <div class="flex gap-2">
                            @switch ($status)
                                @case (OrderStatus::Paid)
                                    <flux:button wire:click="start({{ $order->id }})" variant="primary" class="h-12! flex-1" data-test="start-button">{{ __('Start') }}</flux:button>
                                    @break
                                @case (OrderStatus::Preparing)
                                    <flux:button wire:click="bump({{ $order->id }})" variant="primary" class="h-12! flex-1" data-test="bump-button">{{ __('Bump') }}</flux:button>
                                    @break
                                @case (OrderStatus::Ready)
                                    <flux:button wire:click="recall({{ $order->id }})" class="h-12! flex-1" data-test="recall-button">{{ __('Recall') }}</flux:button>
                                    <flux:button wire:click="pickUp({{ $order->id }})" variant="primary" class="h-12! flex-1" data-test="picked-up-button">{{ __('Picked up') }}</flux:button>
                                    @break
                            @endswitch
                        </div>
                    </article>
                @empty
                    <flux:text class="px-1 py-6 text-center" data-test="empty-column">{{ __('No orders') }}</flux:text>
                @endforelse
            </section>
        @endforeach
    </div>
</div>
