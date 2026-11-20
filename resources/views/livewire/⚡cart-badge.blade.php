<?php

use App\Services\Cart;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Header cart link with the total number of drinks. Refreshes on the 'cart-updated' event.
 * The count isn't re-validated, so it includes lines that are no longer valid.
 */
new class extends Component {
    public int $count = 0;

    public function mount(): void
    {
        $this->refreshCount();
    }

    #[On('cart-updated')]
    public function refreshCount(): void
    {
        $this->count = app(Cart::class)->count();
    }
}; ?>

<a
    href="{{ route('cart') }}"
    wire:navigate
    class="relative inline-flex size-11 items-center justify-center rounded-full text-ink hover:bg-zinc-100"
    aria-label="{{ trans_choice('{0} Cart, empty|{1} Cart, :count drink|[2,*] Cart, :count drinks', $count, ['count' => $count]) }}"
    data-test="cart-link"
>
    <flux:icon.shopping-bag variant="outline" class="size-5" />
    @if ($count > 0)
        <span
            class="absolute -end-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-accent px-1 text-xs font-semibold text-accent-foreground"
            data-test="cart-count"
        >{{ $count }}</span>
    @endif
</a>
