@props(['icon' => 'sparkles'])

{{-- Friendly empty state for customer pages (#62): an icon, a heading, a line of text and a way back. --}}
<div {{ $attributes->class('flex flex-col items-center gap-3 rounded-2xl border border-dashed border-line bg-card px-6 py-10 text-center') }}>
    <span class="flex size-12 items-center justify-center rounded-full bg-zinc-100 text-tea" aria-hidden="true">
        <flux:icon :icon="$icon" class="size-6" />
    </span>
    <flux:heading size="lg" level="2">{{ $heading }}</flux:heading>
    <flux:text class="max-w-sm">{{ $slot }}</flux:text>
    @isset($action)
        <div class="mt-1">{{ $action }}</div>
    @endisset
</div>
