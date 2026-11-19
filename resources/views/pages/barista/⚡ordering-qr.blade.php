<?php

use App\Models\Order;
use App\Support\QrCode;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * T3-7: a big QR of the site's home page (APP_URL) for customers to scan and order: on the
 * counter, or projected on demo day, when APP_URL is the tunnel URL that changes every run.
 * Staff only, like the board. Read-only: no actions, nothing signed, no state changes.
 */
new #[Layout('layouts::app')] #[Title('Ordering QR')] class extends Component {
    public function mount(): void
    {
        $this->authorize('viewBoard', Order::class);
    }

    /** What the QR opens: the home page, as configured (the tunnel URL in demo mode). */
    public function orderingUrl(): string
    {
        return config()->string('app.url');
    }

    /** The QR as an SVG data: URI. */
    public function qr(): string
    {
        return QrCode::dataUri($this->orderingUrl());
    }
}; ?>

<div class="mx-auto flex w-full max-w-3xl flex-col gap-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading size="xl" level="1">{{ __('Ordering QR') }}</flux:heading>
        <div class="flex items-center gap-2">
            <flux:button
                x-data
                x-on:click="document.getElementById('ordering-qr-panel').requestFullscreen?.()"
                icon="arrows-pointing-out"
                size="sm"
                data-test="fullscreen-button"
            >{{ __('Full screen') }}</flux:button>
            <flux:button :href="route('barista')" variant="ghost" size="sm" wire:navigate>{{ __('Barista board') }}</flux:button>
        </div>
    </div>

    {{-- Always white with black text, dark mode included: a camera needs dark modules on a light
         background, and a projector shows white best. --}}
    <section
        id="ordering-qr-panel"
        class="flex flex-col items-center justify-center gap-4 rounded-xl bg-white p-4 text-black sm:p-8 [&:fullscreen_img]:max-w-[min(100%,75vh)]"
        data-test="ordering-qr-panel"
    >
        <p class="text-center text-2xl font-semibold sm:text-4xl">{{ __('Scan to order') }}</p>
        <img
            src="{{ $this->qr() }}"
            alt="{{ __('QR code for :url', ['url' => $this->orderingUrl()]) }}"
            class="block aspect-square h-auto w-full max-w-[min(100%,55vh)]"
            data-test="ordering-qr"
        >
        <p class="break-all text-center font-mono text-base sm:text-2xl" data-test="ordering-qr-url">{{ $this->orderingUrl() }}</p>
    </section>
</div>
