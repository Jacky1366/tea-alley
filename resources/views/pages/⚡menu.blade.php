<?php

use App\Models\Category;
use App\Models\MenuItem;
use App\Support\Money;
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
            // "Popular" uses the admin-set sort_order for now.
            // TODO(Stage 2): switch to 30-day sales volume once orders exist.
            default => $query->orderBy('sort_order'),
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
        <flux:heading size="xl" level="1">{{ __('Menu') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Freshly brewed bubble tea, made to order.') }}</flux:text>
    </div>

    {{-- Online ordering status. The menu stays browsable in every state. --}}
    @if (session('cart-status'))
        <flux:callout icon="check-circle" color="green" data-test="cart-status">
            <flux:callout.text>
                {{ session('cart-status') }}
                <flux:link :href="route('cart')" wire:navigate>{{ __('View cart') }}</flux:link>
            </flux:callout.text>
        </flux:callout>
    @endif

    <x-ordering-banner />

    {{-- Category tabs: horizontally scrollable on phones --}}
    <div class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1" role="tablist" aria-label="{{ __('Categories') }}">
        <flux:button
            size="sm"
            :variant="$this->activeCategory === null ? 'primary' : 'ghost'"
            wire:click="selectCategory('')"
            role="tab"
            :aria-selected="$this->activeCategory === null ? 'true' : 'false'"
            class="shrink-0"
        >
            {{ __('All') }}
        </flux:button>
        @foreach ($this->categories as $tab)
            <flux:button
                size="sm"
                :variant="$this->activeCategory?->is($tab) ? 'primary' : 'ghost'"
                wire:click="selectCategory(@js($tab->slug))"
                wire:key="tab-{{ $tab->id }}"
                role="tab"
                :aria-selected="$this->activeCategory?->is($tab) ? 'true' : 'false'"
                class="shrink-0"
            >
                {{ $tab->localizedName() }}
            </flux:button>
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
        <flux:callout icon="magnifying-glass" data-test="menu-empty">
            <flux:callout.heading>{{ __('No drinks found') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Try another category or search term.') }}</flux:callout.text>
        </flux:callout>
    @else
        {{-- Mobile-first: one column on phones, grid from sm up --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4" data-test="menu-grid">
            @foreach ($this->drinks as $drink)
                <a
                    href="{{ route('menu.show', $drink) }}"
                    wire:navigate
                    wire:key="drink-{{ $drink->id }}"
                    class="block rounded-xl transition hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                    data-test="drink-link"
                >
                <flux:card class="flex h-full flex-col gap-3 overflow-hidden p-0!" data-test="drink-card">
                    @if ($photo = $this->photoUrl($drink))
                        <img src="{{ $photo }}" alt="{{ $drink->localizedName() }}" class="aspect-square w-full object-cover" loading="lazy">
                    @else
                        <div class="flex aspect-square w-full items-center justify-center bg-zinc-100 text-6xl dark:bg-zinc-700" role="img" aria-label="{{ __('No photo') }}">
                            🧋
                        </div>
                    @endif

                    <div class="flex flex-1 flex-col gap-1 px-4 pb-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <flux:heading size="lg" class="truncate">{{ $drink->localizedName() }}</flux:heading>
                            </div>
                            <flux:heading size="lg" class="shrink-0">{{ $this->price($drink) }}</flux:heading>
                        </div>
                        <div>
                            <flux:badge size="sm">{{ $drink->category->localizedName() }}</flux:badge>
                        </div>
                    </div>
                </flux:card>
                </a>
            @endforeach
        </div>
    @endif
</div>
