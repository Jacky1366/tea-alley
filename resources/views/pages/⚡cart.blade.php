<?php

use App\Services\Cart;
use App\Services\CartContents;
use App\Services\OrderingWindow;
use App\Services\PriceBreakdown;
use App\Services\PricingService;
use App\Support\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::public')] #[Title('Cart')] class extends Component {
    /**
     * Every line re-validated against the database on each request.
     */
    #[Computed]
    public function contents(): CartContents
    {
        return app(Cart::class)->contents();
    }

    /**
     * Prices for the valid lines only. PricingService is the only place amounts are calculated.
     */
    #[Computed]
    public function breakdown(): PriceBreakdown
    {
        return app(PricingService::class)->price($this->contents->valid);
    }

    #[Computed]
    public function orderingOpen(): bool
    {
        return app(OrderingWindow::class)->isOpen(now());
    }

    #[Computed]
    public function canCheckout(): bool
    {
        return $this->contents->valid !== []
            && ! $this->contents->hasInvalid()
            && $this->orderingOpen;
    }

    /**
     * Client-supplied key and quantity: Cart ignores unknown keys and rejects
     * quantities outside 1-10 or carts over 20 drinks.
     */
    public function setQuantity(string $key, int $quantity): void
    {
        $this->resetErrorBag();

        app(Cart::class)->setQuantity($key, $quantity);

        $this->cartChanged();
    }

    public function remove(string $key): void
    {
        $this->resetErrorBag();

        app(Cart::class)->remove($key);

        $this->cartChanged();
    }

    public function money(int $cents): string
    {
        return Money::format($cents);
    }

    private function cartChanged(): void
    {
        unset($this->contents, $this->breakdown, $this->canCheckout);

        $this->dispatch('cart-updated');
    }
}; ?>

<div class="mx-auto flex w-full max-w-2xl flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Your cart') }} 購物車</flux:heading>
    </div>

    <x-ordering-banner />

    @if (session('checkout-status'))
        <flux:callout variant="danger" icon="x-circle" data-test="checkout-status">
            <flux:callout.text>{{ session('checkout-status') }}</flux:callout.text>
        </flux:callout>
    @endif

    @foreach (['quantity', 'cart'] as $errorKey)
        @error($errorKey)
            <flux:callout variant="danger" icon="x-circle" data-test="error-{{ $errorKey }}">
                <flux:callout.text>{{ $message }}</flux:callout.text>
            </flux:callout>
        @enderror
    @endforeach

    @if ($this->contents->isEmpty())
        <flux:callout icon="shopping-bag" data-test="cart-empty">
            <flux:callout.heading>{{ __('Your cart is empty') }} 購物車是空的</flux:callout.heading>
            <flux:callout.text>
                <flux:link :href="route('home')" wire:navigate>{{ __('Browse the menu') }}</flux:link>
            </flux:callout.text>
        </flux:callout>
    @else
        @if ($this->contents->valid !== [])
            <ul class="flex flex-col divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="cart-lines">
                @foreach ($this->breakdown->lines as $line)
                    <li class="flex flex-col gap-3 p-4" wire:key="line-{{ $line->key }}" data-test="cart-line">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <flux:heading size="lg">{{ $line->drink->item->name_en }}</flux:heading>
                                <flux:text>{{ $line->drink->item->name_zh }}</flux:text>
                                @if ($summary = $line->drink->optionSummary())
                                    <flux:text size="sm" class="mt-1">{{ $summary }}</flux:text>
                                @endif
                                <flux:text size="sm" class="mt-1">{{ __(':price each', ['price' => $this->money($line->unit_price_cents)]) }}</flux:text>
                            </div>
                            <flux:heading size="lg" class="shrink-0" data-test="line-total">{{ $this->money($line->line_total_cents) }}</flux:heading>
                        </div>

                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-1" role="group" aria-label="{{ __('Quantity') }}">
                                <flux:button
                                    size="sm"
                                    icon="minus"
                                    wire:click="setQuantity(@js($line->key), {{ $line->quantity - 1 }})"
                                    :disabled="$line->quantity <= 1"
                                    aria-label="{{ __('Decrease quantity') }}"
                                    data-test="decrease"
                                />
                                <span class="w-8 text-center font-medium" data-test="quantity">{{ $line->quantity }}</span>
                                <flux:button
                                    size="sm"
                                    icon="plus"
                                    wire:click="setQuantity(@js($line->key), {{ $line->quantity + 1 }})"
                                    :disabled="$line->quantity >= Cart::MAX_PER_LINE"
                                    aria-label="{{ __('Increase quantity') }}"
                                    data-test="increase"
                                />
                            </div>
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="remove(@js($line->key))" data-test="remove">
                                {{ __('Remove') }}
                            </flux:button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($this->contents->hasInvalid())
            <div class="flex flex-col gap-2" data-test="invalid-lines">
                <flux:heading>{{ __("Can't be ordered") }}</flux:heading>
                <ul class="flex flex-col divide-y divide-red-200 rounded-xl border border-red-200 dark:divide-red-900 dark:border-red-900">
                    @foreach ($this->contents->invalid as $line)
                        <li class="flex items-start justify-between gap-3 p-4" wire:key="invalid-{{ $line->key }}" data-test="invalid-line">
                            <div class="min-w-0">
                                <flux:heading>{{ $line->name ?? __('Unavailable drink') }} × {{ $line->quantity }}</flux:heading>
                                @foreach ($line->reasons as $reason)
                                    <flux:text size="sm" class="text-red-600! dark:text-red-400!" data-test="invalid-reason">{{ $reason }}</flux:text>
                                @endforeach
                            </div>
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="remove(@js($line->key))" data-test="remove-invalid">
                                {{ __('Remove') }}
                            </flux:button>
                        </li>
                    @endforeach
                </ul>
                <flux:text size="sm">{{ __('Remove these items to check out.') }}</flux:text>
            </div>
        @endif

        <div class="flex flex-col gap-1">
            <div class="flex items-baseline justify-between">
                <flux:heading size="lg">{{ __('Subtotal') }}</flux:heading>
                <flux:heading size="lg" data-test="subtotal">{{ $this->money($this->breakdown->subtotal_cents) }}</flux:heading>
            </div>
            <flux:text size="sm">{{ __('Taxes calculated at checkout') }}</flux:text>
        </div>

        @if ($this->canCheckout)
            <flux:button :href="route('checkout')" variant="primary" class="w-full" data-test="checkout-button">
                {{ __('Checkout') }}
            </flux:button>
        @else
            <flux:button variant="primary" class="w-full" disabled data-test="checkout-button">
                {{ __('Checkout') }}
            </flux:button>
        @endif
    @endif
</div>
