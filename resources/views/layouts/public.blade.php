<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:header container class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <x-app-logo href="{{ route('home') }}" wire:navigate />

            <flux:spacer />

            <nav class="flex items-center gap-2" data-test="public-nav">
                <livewire:cart-badge />
                @auth
                    {{-- Same pattern as the sidebar's user menu (components/desktop-user-menu). --}}
                    <flux:dropdown position="bottom" align="end">
                        <flux:profile
                            :initials="auth()->user()->initials()"
                            icon-trailing="chevron-down"
                            data-test="user-menu-button"
                        />

                        <flux:menu data-test="user-menu">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />
                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>

                            <flux:menu.separator />

                            <flux:menu.radio.group>
                                <flux:menu.item :href="route('orders.index')" icon="receipt-percent" wire:navigate data-test="my-orders-link">
                                    {{ __('My orders') }}
                                </flux:menu.item>
                                @if (auth()->user()->canManageMenu())
                                    {{-- Filament is a separate app, so a normal link rather than wire:navigate. --}}
                                    <flux:menu.item href="/admin" icon="wrench-screwdriver" data-test="admin-link">
                                        {{ __('Admin') }}
                                    </flux:menu.item>
                                @endif
                                <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate data-test="settings-link">
                                    {{ __('Settings') }}
                                </flux:menu.item>
                            </flux:menu.radio.group>

                            <flux:menu.separator />

                            <form method="POST" action="{{ route('logout') }}" class="w-full">
                                @csrf
                                <flux:menu.item
                                    as="button"
                                    type="submit"
                                    icon="arrow-right-start-on-rectangle"
                                    class="w-full cursor-pointer"
                                    data-test="logout-button"
                                >
                                    {{ __('Log out') }}
                                </flux:menu.item>
                            </form>
                        </flux:menu>
                    </flux:dropdown>
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
