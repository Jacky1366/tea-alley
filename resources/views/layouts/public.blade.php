<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:header container class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:brand href="{{ route('home') }}" name="Tea Alley 茶巷" wire:navigate>
                <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
                    <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
                </x-slot>
            </flux:brand>

            <flux:spacer />

            <nav class="flex items-center gap-2" data-test="public-nav">
                @auth
                    @if (auth()->user()->canManageMenu())
                        <flux:button href="/admin" variant="ghost" size="sm" icon="cog-6-tooth">
                            {{ __('Admin') }}
                        </flux:button>
                    @endif
                    <flux:button :href="route('dashboard')" variant="primary" size="sm" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:button>
                @else
                    <flux:button :href="route('login')" variant="ghost" size="sm" wire:navigate>
                        {{ __('Log in') }}
                    </flux:button>
                    @if (Route::has('register'))
                        <flux:button :href="route('register')" variant="primary" size="sm" wire:navigate>
                            {{ __('Register') }}
                        </flux:button>
                    @endif
                @endauth
            </nav>
        </flux:header>

        <flux:main container>
            {{ $slot }}
        </flux:main>

        @fluxScripts
    </body>
</html>
