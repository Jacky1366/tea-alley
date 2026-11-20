<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['customerTheme' => true])
    </head>
    {{-- Register, log in, verify email and the other auth pages: the warm, light-only customer theme (#62). --}}
    <body class="theme-customer min-h-screen bg-paper text-ink antialiased">
        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-4 sm:p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col gap-4">
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-2 rounded-lg" wire:navigate>
                    <x-app-logo-icon class="size-12 fill-current text-tea" aria-hidden="true" />
                    <span class="font-display text-lg font-semibold text-ink">Tea Alley 茶巷</span>
                </a>
                <div class="flex flex-col gap-6 rounded-2xl border border-line bg-card p-6 shadow-warm sm:p-8">
                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
