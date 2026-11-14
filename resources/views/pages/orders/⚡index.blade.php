<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Support\LocalTime;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::public')] #[Title('My orders')] class extends Component {
    use WithPagination;

    public const PER_PAGE = 10;

    /**
     * The signed-in user's orders, newest first. Two queries whatever the page holds:
     * the paginator's count, and the page itself with the drink count as a SUM subquery.
     *
     * @return LengthAwarePaginator<int, Order>
     */
    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user->orders()
            ->withSum('items as drink_count', 'quantity')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);
    }

    public function drinkCount(Order $order): int
    {
        return intval($order->getAttribute('drink_count'));
    }

    public function money(int $cents): string
    {
        return Money::format($cents);
    }

}; ?>

<div class="mx-auto flex w-full max-w-2xl flex-col gap-6">
    <flux:heading size="xl" level="1">{{ __('My orders') }}</flux:heading>

    @if ($this->orders->isEmpty())
        <flux:callout icon="receipt-percent" data-test="no-orders">
            <flux:callout.heading>{{ __('No orders yet') }}</flux:callout.heading>
            <flux:callout.text>
                <flux:link :href="route('home')" wire:navigate>{{ __('Browse the menu') }}</flux:link>
            </flux:callout.text>
        </flux:callout>
    @else
        <ul class="flex flex-col divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="order-list">
            @foreach ($this->orders as $order)
                <li wire:key="order-{{ $order->id }}">
                    <a href="{{ route('orders.show', $order) }}" wire:navigate class="flex items-center justify-between gap-3 p-4 hover:bg-zinc-50 dark:hover:bg-zinc-700/50" data-test="order-row">
                        <div class="min-w-0">
                            <flux:heading data-test="order-number">{{ $order->order_number }}</flux:heading>
                            <flux:text size="sm">{{ LocalTime::format($order->created_at, 'date_time') }}</flux:text>
                            <flux:text size="sm">{{ trans_choice(':count drink|:count drinks', $this->drinkCount($order)) }}</flux:text>
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-1">
                            <flux:heading>{{ $this->money($order->total_cents) }}</flux:heading>
                            <flux:badge size="sm" :color="$order->status === OrderStatus::PendingPayment ? 'amber' : ($order->status === OrderStatus::Cancelled ? 'zinc' : 'green')">
                                {{ $order->status->label() }}
                            </flux:badge>
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>

        {{ $this->orders->links() }}
    @endif
</div>
