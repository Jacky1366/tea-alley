@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand name="Tea Alley 茶巷" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
            <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
        </x-slot>
    </flux:sidebar.brand>
@else
    {{-- Only the customer header (layouts/public) uses this one: the logo in tea brown, the name in Fraunces (#62). --}}
    <a {{ $attributes->class('me-4 flex h-10 items-center gap-2 rounded-lg') }} data-flux-brand>
        <x-app-logo-icon class="size-8 shrink-0 fill-current text-tea" aria-hidden="true" />
        <span class="truncate font-display text-base font-semibold text-ink">Tea Alley 茶巷</span>
    </a>
@endif
