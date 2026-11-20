<?php

use App\Models\Category;
use App\Models\MenuItem;
use App\Services\SalesReport;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::public')] #[Title('Menu')] class extends Component {
    public const SORTS = ['popular', 'price_asc', 'price_desc'];

    /** Active category slug; empty means "All". */
    #[Url]
    public string $category = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $sort = 'popular';

    /**
     * Only active categories get a tab.
     *
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Category::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * URL values are user input: an unknown or inactive slug falls back to "All".
     */
    #[Computed]
    public function activeCategory(): ?Category
    {
        return $this->categories->firstWhere('slug', $this->category);
    }

    /**
     * @return Collection<int, MenuItem>
     */
    #[Computed]
    public function drinks(): Collection
    {
        $term = trim(mb_substr($this->search, 0, 100));

        $query = MenuItem::query()
            ->with('category')
            ->where('is_available', true)
            ->whereIn('category_id', $this->categories->modelKeys());

        if ($this->activeCategory !== null) {
            $query->where('category_id', $this->activeCategory->id);
        }

        if ($term !== '') {
            // Bound parameters, so the term can't inject SQL. A literal % or _ in the term acts as a wildcard,
            // which is harmless on a public, read-only menu.
            $query->where(fn ($q) => $q
                ->where('name_en', 'like', "%{$term}%")
                ->orWhere('name_zh', 'like', "%{$term}%"));
        }

        match ($this->sort) {
            'price_asc' => $query->orderBy('price_cents'),
            'price_desc' => $query->orderByDesc('price_cents'),
            // "Popular": drinks sold in the last 30 Vancouver days (paid-or-later orders,
            // SalesReport's definition, the same as the dashboard), counted by menu_item_id so
            // a renamed drink keeps its sales. Ties, and drinks with no sales, keep the admin's
            // sort_order. One subquery, read on every request like the rest of the menu.
            default => $query
                ->withSum(['orderItems as units_sold' => fn (Builder $lines) => SalesReport::soldSince($lines, SalesReport::recentStart())], 'quantity')
                ->orderByDesc('units_sold')
                ->orderBy('sort_order'),
        };

        return $query->orderBy('id')->get();
    }

    public function selectCategory(string $slug): void
    {
        $this->category = $slug;
    }

    public function photoUrl(MenuItem $item): ?string
    {
        return $item->photo_path === null ? null : Storage::disk('public')->url($item->photo_path);
    }

    /**
     * Literal keys, so the missing-translation test can find them.
     */
    public function sortLabel(string $sort): string
    {
        return match ($sort) {
            'price_asc' => __('Price: low to high'),
            'price_desc' => __('Price: high to low'),
            default => __('Most popular'),
        };
    }

    public function price(MenuItem $item): string
    {
        return Money::format($item->price_cents);
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1" class="text-3xl!">{{ __('Menu') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Freshly brewed bubble tea, made to order.') }}</flux:text>
    </div>

    {{--
        "Added to cart" toast (Flux Pro's toast isn't installed). The live region is rendered empty and
        filled a moment later, so screen readers announce it. Hides itself after about 3 seconds;
        no slide or fade with prefers-reduced-motion.
    --}}
    @if (session('cart-status'))
        <div
            class="pointer-events-none fixed inset-x-0 bottom-4 z-30 flex justify-center px-4 sm:bottom-6"
            x-data="{ shown: false }"
            x-init="$nextTick(() => { shown = true; setTimeout(() => shown = false, 3200) })"
            data-test="cart-status"
        >
            <div role="status" aria-live="polite" class="w-full max-w-sm">
                <div
                    x-show="shown"
                    x-transition:enter="transition duration-200 ease-out motion-reduce:transition-none"
                    x-transition:enter-start="translate-y-2 opacity-0"
                    x-transition:leave="transition duration-300 ease-in motion-reduce:transition-none"
                    x-transition:leave-end="opacity-0"
                    style="display: none"
                    class="pointer-events-auto flex items-center gap-3 rounded-2xl bg-ink px-4 py-3 text-sm text-paper shadow-warm-lg"
                >
                    <flux:icon.check-circle variant="mini" class="shrink-0 text-green-300" />
                    <p class="flex-1">{{ session('cart-status') }}</p>
                    <a href="{{ route('cart') }}" wire:navigate class="shrink-0 font-semibold text-paper underline underline-offset-2">{{ __('View cart') }}</a>
                </div>
            </div>
        </div>
    @endif

    {{-- Online ordering status. The menu stays browsable in every state. --}}

    <x-ordering-banner />

    {{--
        Category filter: toggle buttons (aria-pressed) in a labelled group, not a tablist. They filter
        the grid rather than switch panels, and plain buttons need no arrow-key handling.
        Horizontally scrollable on phones (the selected one is scrolled into view on load); 44px tall tap targets.
    --}}
    <div
        class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none] sm:mx-0 sm:px-0"
        role="group"
        aria-label="{{ __('Categories') }}"
        x-data
        x-init="$el.querySelector('[aria-pressed=true]')?.scrollIntoView({ inline: 'center', block: 'nearest' })"
    >
        @php($tabClass = 'inline-flex h-11 shrink-0 items-center rounded-full border px-4 text-sm font-medium transition-colors')
        @php($activeTab = 'border-tea bg-tea text-card shadow-warm')
        @php($idleTab = 'border-line bg-card text-ink hover:border-tea/40 hover:bg-zinc-50')
        <button
            type="button"
            wire:click="selectCategory('')"
            wire:target="selectCategory('')"
            wire:loading.class="opacity-60"
            aria-pressed="{{ $this->activeCategory === null ? 'true' : 'false' }}"
            class="{{ $tabClass }} {{ $this->activeCategory === null ? $activeTab : $idleTab }}"
        >
            {{ __('All') }}
        </button>
        @foreach ($this->categories as $tab)
            <button
                type="button"
                wire:click="selectCategory({{ \Illuminate\Support\Js::from($tab->slug) }})"
                wire:target="selectCategory({{ \Illuminate\Support\Js::from($tab->slug) }})"
                wire:loading.class="opacity-60"
                wire:key="tab-{{ $tab->id }}"
                aria-pressed="{{ $this->activeCategory?->is($tab) ? 'true' : 'false' }}"
                class="{{ $tabClass }} {{ $this->activeCategory?->is($tab) ? $activeTab : $idleTab }}"
            >
                {{ $tab->localizedName() }}
            </button>
        @endforeach
    </div>

    <div class="flex flex-col gap-3 sm:flex-row">
        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            :placeholder="__('Search drinks…')"
            maxlength="100"
            clearable
            class="sm:flex-1"
        />
        <flux:select wire:model.live="sort" class="sm:max-w-56" :aria-label="__('Sort')">
            @foreach ($this::SORTS as $value)
                <flux:select.option :value="$value">{{ $this->sortLabel($value) }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($this->drinks->isEmpty())
        <x-empty-state icon="magnifying-glass" data-test="menu-empty">
            @if (trim($search) !== '')
                <x-slot:heading>{{ __('No drinks found') }}</x-slot:heading>
                {{ __('Nothing matches “:term”. Try another word, or browse the whole menu.', ['term' => mb_substr(trim($search), 0, 100)]) }}
            @elseif ($this->activeCategory !== null)
                <x-slot:heading>{{ __('Nothing in :category right now', ['category' => $this->activeCategory->localizedName()]) }}</x-slot:heading>
                {{ __('Check back soon, or have a look at the rest of the menu.') }}
            @else
                <x-slot:heading>{{ __('No drinks found') }}</x-slot:heading>
                {{ __('Try another category or search term.') }}
            @endif
            <x-slot:action>
                <flux:button :href="route('home')" wire:navigate variant="primary" size="sm">{{ __('See all drinks') }}</flux:button>
            </x-slot:action>
        </x-empty-state>
    @else
        {{-- Mobile first: two columns on phones (390px), up to four on desktop. --}}
        <div class="grid grid-cols-2 gap-3 sm:gap-5 md:grid-cols-3 lg:grid-cols-4" data-test="menu-grid">
            @foreach ($this->drinks as $drink)
                <a
                    href="{{ route('menu.show', $drink) }}"
                    wire:navigate
                    wire:key="drink-{{ $drink->id }}"
                    class="group flex flex-col overflow-hidden rounded-2xl border border-line bg-card shadow-warm transition duration-200 hover:-translate-y-0.5 hover:shadow-warm-lg motion-reduce:transition-none motion-reduce:hover:translate-y-0"
                    data-test="drink-link"
                >
                    <div class="flex flex-1 flex-col" data-test="drink-card">
                        {{-- Photos are 4:5 portraits (700x875), already framed: shown whole, never cropped further. --}}
                        @if ($photo = $this->photoUrl($drink))
                            <img
                                src="{{ $photo }}"
                                alt="{{ $drink->localizedName() }}"
                                width="700"
                                height="875"
                                class="aspect-[4/5] h-auto w-full bg-roast object-cover"
                                loading="{{ $loop->index < 4 ? 'eager' : 'lazy' }}"
                                decoding="async"
                            >
                        @else
                            {{-- No photo yet: a dark warm block like the photos' background, so a half-photographed menu stays consistent. --}}
                            <div class="flex aspect-[4/5] w-full items-center justify-center bg-radial-[at_50%_35%] from-roast-light to-roast to-70%" aria-hidden="true" data-test="photo-placeholder">
                                <x-app-logo-icon class="size-1/4 fill-current text-paper/90" />
                            </div>
                        @endif

                        <div class="flex flex-1 flex-col gap-1 p-3 sm:p-4">
                            <flux:heading level="2" class="line-clamp-2 text-[0.95rem]! leading-snug! sm:text-base!">{{ $drink->localizedName() }}</flux:heading>
                            <div class="mt-auto flex items-end justify-between gap-2 pt-1">
                                <span class="truncate text-xs text-ink-muted">{{ $drink->category->localizedName() }}</span>
                                <span class="shrink-0 font-semibold text-tea">{{ $this->price($drink) }}</span>
                            </div>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
