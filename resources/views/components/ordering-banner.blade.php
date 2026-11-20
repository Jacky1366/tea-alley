{{-- Customer pages only (menu, drink, cart, checkout). Warm theme (#62): a card with a status dot. --}}
@php
    [$test, $dot, $text] = match ($state) {
        'open' => ['ordering-open', 'bg-matcha', __('Online ordering is open until :time', ['time' => $at ? \App\Support\LocalTime::format($at, 'time') : ''])],
        'closed' => ['ordering-closed', 'bg-tea', __('Online ordering opens :time', ['time' => $at ? \App\Support\LocalTime::format($at, 'day_time') : ''])],
        default => ['ordering-unavailable', 'bg-ink-muted', __('Online ordering is currently unavailable')],
    };
@endphp

<div class="flex items-center gap-3 rounded-2xl border border-line bg-card px-4 py-3 text-sm text-ink shadow-warm" data-test="{{ $test }}">
    <span class="relative flex size-2.5 shrink-0" aria-hidden="true">
        @if ($state === 'open')
            <span class="absolute inline-flex size-full animate-ping rounded-full bg-matcha opacity-60 motion-reduce:hidden"></span>
        @endif
        <span class="relative inline-flex size-2.5 rounded-full {{ $dot }}"></span>
    </span>
    <p>{{ $text }}</p>
</div>
