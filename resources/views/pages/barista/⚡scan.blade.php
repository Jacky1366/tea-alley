<?php

use App\Models\Order;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * T3-1: scan a customer's pickup QR with this device's camera (resources/js/qr-scanner.js).
 * No actions: the camera and decoding run in the browser, and a valid code just opens the
 * pickup confirmation page on this site. Anything else is ignored (no open redirect).
 * Without a camera, staff use Picked up on the board.
 */
new #[Layout('layouts::app')] #[Title('Scan pickup QR')] class extends Component {
    public function mount(): void
    {
        $this->authorize('viewBoard', Order::class);
    }
}; ?>

<div class="mx-auto flex w-full max-w-xl flex-col gap-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading size="xl" level="1">{{ __('Scan pickup QR') }}</flux:heading>
        <flux:button :href="route('barista')" variant="ghost" size="sm" wire:navigate>{{ __('Barista board') }}</flux:button>
    </div>

    {{-- wire:ignore: the scanner script owns everything inside. Messages are rendered here so
         they're translated; the script only shows and hides them. --}}
    <div
        wire:ignore
        data-qr-scanner
        data-pickup-path="{{ parse_url(route('barista.pickup', ['order' => 'TA-000000']), PHP_URL_PATH) }}"
        class="flex flex-col gap-3"
    >
        <div class="relative overflow-hidden rounded-xl bg-black">
            {{-- playsinline + muted: iOS Safari otherwise goes fullscreen or shows nothing. --}}
            <video data-qr-video class="aspect-square w-full object-cover" playsinline muted autoplay></video>
            <div class="pointer-events-none absolute inset-[15%] rounded-lg border-4 border-white/80"></div>
        </div>

        <flux:text data-qr-message="scanning">{{ __('Point the camera at the customer\'s pickup QR code.') }}</flux:text>
        <flux:callout variant="warning" icon="exclamation-triangle" data-qr-message="foreign" class="hidden">
            <flux:callout.text>{{ __('That\'s not a Tea Alley pickup code. Scan the QR code on the customer\'s order page.') }}</flux:callout.text>
        </flux:callout>
        <flux:callout variant="success" icon="check-circle" data-qr-message="found" class="hidden">
            <flux:callout.text>{{ __('Found it. Opening the order…') }}</flux:callout.text>
        </flux:callout>
        <flux:callout variant="danger" icon="video-camera-slash" data-qr-message="blocked" class="hidden">
            <flux:callout.heading>{{ __('The camera is blocked') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Allow camera access for this site, or use Picked up on the barista board.') }}</flux:callout.text>
        </flux:callout>
        <flux:callout variant="danger" icon="video-camera-slash" data-qr-message="unavailable" class="hidden">
            <flux:callout.heading>{{ __('No camera available') }}</flux:callout.heading>
            <flux:callout.text>{{ __('This device has no camera we can use. Use Picked up on the barista board.') }}</flux:callout.text>
        </flux:callout>
    </div>
</div>
