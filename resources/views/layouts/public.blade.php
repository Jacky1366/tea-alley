<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['customerTheme' => true])
    </head>
    {{-- theme-customer: warm, light-only customer theme (#62, resources/css/app.css). --}}
    <body class="theme-customer min-h-screen bg-paper text-ink antialiased">
        <flux:header container class="sticky top-0 z-20 border-b border-line bg-card/90 backdrop-blur-sm">
            <x-app-logo href="{{ route('home') }}" wire:navigate />

            <flux:spacer />

            <nav class="flex items-center gap-1 sm:gap-2" data-test="public-nav">
                <livewire:cart-badge />

                {{-- Language switch: shows the language it switches to, written in that language. It's a plain
                     form POST, so Livewire's loading states don't apply: Alpine disables the button once sent
                     (the submit event bubbles to this wrapper). --}}
                @php($otherLocale = \App\Enums\Locale::current()->other())
                <div x-data="{ busy: false }" x-on:submit="busy = true">
                    <form method="POST" action="{{ route('locale.switch') }}" data-test="locale-switch">
                        @csrf
                        <input type="hidden" name="locale" value="{{ $otherLocale->value }}">
                        <flux:button type="submit" variant="ghost" size="sm" lang="{{ $otherLocale->htmlLang() }}" :aria-label="__('Switch language')" x-bind:disabled="busy" x-bind:aria-busy="busy" data-test="locale-switch-button">
                            {{ $otherLocale->switchLabel() }}
                        </flux:button>
                    </form>
                </div>

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
                                @if (auth()->user()->canUseBaristaBoard())
                                    <flux:menu.item :href="route('barista')" icon="queue-list" wire:navigate data-test="barista-link">
                                        {{ __('Barista board') }}
                                    </flux:menu.item>
                                @endif
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
                    {{-- Most demo visitors are new, so Register is the tea-brown button on every width. On phones,
                         Log in is hidden when both don't fit; the register page links to log in. --}}
                    <flux:button :href="route('login')" variant="ghost" size="sm" class="max-sm:hidden" wire:navigate data-test="login-link">
                        {{ __('Log in') }}
                    </flux:button>
                    @if (Route::has('register'))
                        <flux:button :href="route('register')" variant="primary" size="sm" wire:navigate data-test="register-link">
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
