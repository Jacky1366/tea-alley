@props(['channel'])

{{--
    The Live / Polling indicator, and the refresher behind it (realtimeRefresh in
    resources/js/echo.js). Place it inside a Livewire component; $channel is the Pusher
    channel name, e.g. "private-barista". wire:ignore: Alpine owns this element, so a
    re-render doesn't reset it.
--}}
<div
    wire:ignore
    x-data="realtimeRefresh(@js($channel))"
    {{ $attributes->class('flex items-center gap-1.5 text-sm') }}
    data-test="realtime-status"
    data-channel="{{ $channel }}"
>
    <span class="size-2 rounded-full bg-amber-500" :class="live ? 'bg-green-500!' : ''" aria-hidden="true"></span>
    <span class="text-zinc-600 dark:text-zinc-300" x-show="live" style="display: none" data-test="realtime-live">{{ __('Live') }}</span>
    <span class="text-zinc-600 dark:text-zinc-300" x-show="!live" data-test="realtime-polling">{{ __('Polling') }}</span>
</div>
