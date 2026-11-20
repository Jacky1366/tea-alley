<?php

use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Services\Cart;
use App\Services\ModifierValidator;
use App\Services\OrderingWindow;
use App\Support\Money;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::public')] #[Title('Customize your drink')] class extends Component {
    /** Locked: Livewire rejects any client change. The drink is re-queried from this on every request. */
    #[Locked]
    public int $itemId;

    /**
     * Group id => chosen option id (single-select; '' means "None") or list of option ids (multi-select).
     * Client-controlled, so never trusted: addToCart() ignores the group keys and ModifierValidator
     * checks every id against the database.
     *
     * @var array<array-key, mixed>
     */
    public array $selected = [];

    public int $quantity = 1;

    public function mount(MenuItem $item): void
    {
        $item->loadMissing('category');

        abort_unless($item->category->is_active, 404);

        $this->itemId = $item->id;

        // Preselect available defaults, first by sort_order, never more than the group allows.
        foreach ($this->item->modifierGroups as $group) {
            $defaults = $group->options
                ->filter(fn (ModifierOption $option): bool => $option->is_default && $option->is_available)
                ->take($group->max_select)
                ->values() // filter() keeps the original keys; reindex so $defaults[0] is the first default
                ->modelKeys();

            $this->selected[$group->id] = $group->max_select === 1 ? ($defaults[0] ?? '') : $defaults;
        }
    }

    #[Computed]
    public function item(): MenuItem
    {
        return MenuItem::query()->with(MenuItem::builderRelations())->findOrFail($this->itemId);
    }

    #[Computed]
    public function orderingOpen(): bool
    {
        return app(OrderingWindow::class)->isOpen(now());
    }

    /**
     * True if some group can't be satisfied because too few of its options are available.
     */
    #[Computed]
    public function requiredOptionsSoldOut(): bool
    {
        return $this->item->modifierGroups->contains(
            fn (ModifierGroup $group): bool => $group->options->where('is_available', true)->count() < $group->effectiveMin(),
        );
    }

    #[Computed]
    public function canOrder(): bool
    {
        return $this->item->is_available && $this->orderingOpen && ! $this->requiredOptionsSoldOut;
    }

    /**
     * Prices for the Alpine total. Display only: nothing here is ever sent back or trusted.
     *
     * @return array{base: int, deltas: array<int, int>}
     */
    #[Computed]
    public function priceData(): array
    {
        return [
            'base' => $this->item->price_cents,
            'deltas' => $this->item->modifierGroups
                ->flatMap(fn (ModifierGroup $group) => $group->options)
                ->mapWithKeys(fn (ModifierOption $option): array => [$option->id => $option->price_delta_cents])
                ->all(),
        ];
    }

    /**
     * Server-rendered starting total, so the price shows before Alpine runs.
     *
     * DISPLAY-ONLY PREVIEW: never stored or charged. PricingService is the source of
     * truth for every amount that is (see CLAUDE.md, Data rules).
     */
    public function displayTotal(): string
    {
        $deltas = $this->priceData['deltas'];
        $cents = $this->priceData['base'];

        foreach ($this->chosenOptionIds() as $id) {
            $cents += is_int($id) || is_string($id) ? ($deltas[$id] ?? 0) : 0;
        }

        return Money::format($cents * max($this->quantity, 0));
    }

    /**
     * Add the drink to the cart. Takes no arguments: everything comes from component state.
     * The ordering window is checked here on the server (the disabled button is only a hint),
     * then ModifierValidator re-checks the selection against the database.
     */
    public function addToCart(): void
    {
        $cart = app(Cart::class);

        $this->resetErrorBag();

        if (! app(OrderingWindow::class)->isOpen(now())) {
            throw ValidationException::withMessages([
                'ordering' => __('Online ordering is closed right now, so drinks can\'t be added to the cart.'),
            ]);
        }

        $cart->add(app(ModifierValidator::class)->validate($this->item, $this->chosenOptionIds(), $this->quantity));

        $this->dispatch('cart-updated');
        session()->flash('cart-status', __(':drink added to cart.', ['drink' => $this->item->localizedName()]));

        $this->redirectRoute('home', navigate: true);
    }

    /**
     * Flatten $selected into raw option ids. The group keys are ignored: the validator
     * regroups each option by its real group. "None" ('') and empty values are skipped;
     * anything else, including nested arrays, goes to the validator to be rejected.
     *
     * @return list<mixed>
     */
    private function chosenOptionIds(): array
    {
        $ids = [];

        foreach ($this->selected as $value) {
            foreach (is_array($value) ? $value : [$value] as $id) {
                if ($id !== '' && $id !== null) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    public function photoUrl(): ?string
    {
        return $this->item->photo_path === null ? null : Storage::disk('public')->url($this->item->photo_path);
    }

    public function money(int $cents): string
    {
        return Money::format($cents);
    }
}; ?>

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div>
        <flux:button :href="route('home')" wire:navigate variant="ghost" size="sm" icon="arrow-left" class="-ms-2">
            {{ __('Back to menu') }}
        </flux:button>
    </div>

    <x-ordering-banner />

    {{-- Desktop: photo beside the options. Phones: photo on top, capped in height so the options stay close. --}}
    <div class="grid gap-6 md:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] md:items-start md:gap-10">
        @php($soldOut = ! $this->item->is_available)
        <div class="md:sticky md:top-24">
            @if ($photo = $this->photoUrl())
                <img
                    src="{{ $photo }}"
                    alt="{{ $this->item->localizedName() }}"
                    width="700"
                    height="875"
                    @class([
                        'mx-auto aspect-[4/5] h-[42svh] w-auto rounded-2xl bg-roast object-cover shadow-warm-lg md:h-auto md:w-full',
                        'grayscale' => $soldOut,
                    ])
                    data-test="drink-photo"
                >
            @else
                <div
                    @class([
                        'mx-auto flex aspect-[4/5] h-[42svh] w-auto items-center justify-center rounded-2xl bg-radial-[at_50%_35%] from-roast-light to-roast to-70% shadow-warm-lg md:h-auto md:w-full',
                        'grayscale' => $soldOut,
                    ])
                    aria-hidden="true"
                    data-test="photo-placeholder"
                >
                    <x-app-logo-icon class="size-1/4 fill-current text-paper/90" />
                </div>
            @endif
        </div>

        <div class="flex min-w-0 flex-col gap-6">
            <div class="flex flex-col gap-2">
                <div class="flex flex-wrap items-center gap-2">
                    <flux:heading size="xl" level="1" class="text-3xl!">{{ $this->item->localizedName() }}</flux:heading>
                    @if ($soldOut)
                        <span class="rounded-full bg-clay px-2.5 py-0.5 text-xs font-semibold text-card" data-test="sold-out">{{ __('Sold out') }}</span>
                    @endif
                </div>
                @if ($description = $this->item->localizedDescription())
                    <flux:text class="text-base!" data-test="description">{{ $description }}</flux:text>
                @endif
                <p class="text-xl font-semibold text-tea" data-test="base-price">{{ $this->money($this->item->price_cents) }}</p>
            </div>

            @error('item')
                <flux:callout variant="danger" icon="x-circle" data-test="error-item">
                    <flux:callout.text>{{ $message }}</flux:callout.text>
                </flux:callout>
            @enderror

            {{--
                DISPLAY-ONLY PREVIEW: the Alpine totalCents below is a live estimate for the customer.
                It is never sent to the server, stored, or charged. PricingService is the source of
                truth for every amount that is (see CLAUDE.md, Data rules).
            --}}
            <form
                wire:submit="addToCart"
                class="flex flex-col gap-6"
                x-data="{
                    base: @js($this->priceData['base']),
                    deltas: @js((object) $this->priceData['deltas']),
                    get totalCents() {
                        let cents = this.base;
                        for (const value of Object.values(this.$wire.selected ?? {})) {
                            for (const id of [].concat(value)) {
                                cents += this.deltas[id] ?? 0;
                            }
                        }
                        const quantity = parseInt(this.$wire.quantity, 10);
                        return cents * (Number.isInteger(quantity) ? quantity : 0);
                    },
                    money(cents) {
                        return '$' + (cents / 100).toFixed(2);
                    },
                }"
            >
                @if ($errors->has('selected'))
                    <flux:callout variant="danger" icon="x-circle" data-test="error-selected">
                        @foreach ($errors->get('selected') as $message)
                            <flux:callout.text>{{ $message }}</flux:callout.text>
                        @endforeach
                    </flux:callout>
                @endif

                {{-- Options stay real radio buttons and checkboxes (keyboard and screen readers); rows are 48px tall. --}}
                @php($optionRow = 'flex min-h-12 items-center gap-3 px-4 py-2 transition-colors has-[:checked]:bg-zinc-50 has-[:focus-visible]:outline-2 has-[:focus-visible]:-outline-offset-2 has-[:focus-visible]:outline-tea')
                @foreach ($this->item->modifierGroups as $group)
                    @php($single = $group->max_select === 1)
                    <fieldset class="flex flex-col gap-2" wire:key="group-{{ $group->id }}" data-test="group-{{ $group->id }}">
                        <legend class="mb-2 flex w-full flex-wrap items-baseline justify-between gap-2">
                            <span class="font-display text-lg font-semibold text-ink">{{ $group->localizedName() }}</span>
                            <span class="text-sm text-ink-muted">
                                @if ($group->effectiveMin() > 0)
                                    {{ __('Required') }}
                                @else
                                    {{ __('Optional') }}
                                @endif
                                @unless ($single)
                                    · {{ __('up to :max', ['max' => $group->max_select]) }}
                                @endunless
                            </span>
                        </legend>

                        <div class="flex flex-col divide-y divide-line overflow-hidden rounded-2xl border border-line bg-card">
                            @if ($single && $group->effectiveMin() === 0)
                                <label class="{{ $optionRow }} cursor-pointer">
                                    <input type="radio" name="group-{{ $group->id }}" value="" wire:model="selected.{{ $group->id }}" class="size-5 shrink-0 accent-[var(--color-accent)] focus-visible:outline-none" data-test="option-none-{{ $group->id }}">
                                    <span class="flex-1">{{ __('None') }}</span>
                                </label>
                            @endif

                            @foreach ($group->options as $option)
                                <label
                                    wire:key="option-{{ $option->id }}"
                                    @class([
                                        $optionRow,
                                        'cursor-pointer' => $option->is_available,
                                        'cursor-not-allowed text-ink-muted' => ! $option->is_available,
                                    ])
                                >
                                    <input
                                        type="{{ $single ? 'radio' : 'checkbox' }}"
                                        name="group-{{ $group->id }}{{ $single ? '' : '[]' }}"
                                        value="{{ $option->id }}"
                                        wire:model="selected.{{ $group->id }}"
                                        @disabled(! $option->is_available)
                                        class="size-5 shrink-0 accent-[var(--color-accent)] focus-visible:outline-none"
                                        data-test="option-{{ $option->id }}"
                                    >
                                    <span class="min-w-0 flex-1">
                                        {{ $option->localizedName() }}
                                        @unless ($option->is_available)
                                            <span class="ms-1 rounded-full border border-clay px-2 py-0.5 text-xs font-medium text-clay">{{ __('Sold out') }}</span>
                                        @endunless
                                    </span>
                                    @if ($option->price_delta_cents > 0)
                                        <span class="shrink-0 text-sm text-ink-muted">+{{ $this->money($option->price_delta_cents) }}</span>
                                    @endif
                                </label>
                            @endforeach
                        </div>

                        @foreach ($errors->get('selected.'.$group->id) as $message)
                            <flux:text class="text-red-600!" data-test="error-group-{{ $group->id }}">{{ $message }}</flux:text>
                        @endforeach
                    </fieldset>
                @endforeach

                @if ($this->requiredOptionsSoldOut && $this->item->is_available)
                    <flux:callout icon="exclamation-triangle" color="amber" data-test="required-sold-out">
                        <flux:callout.text>{{ __('Some required options are sold out') }}</flux:callout.text>
                    </flux:callout>
                @endif

                @foreach (['ordering', 'cart'] as $errorKey)
                    @error($errorKey)
                        <flux:callout variant="danger" icon="x-circle" data-test="error-{{ $errorKey }}">
                            <flux:callout.text>{{ $message }}</flux:callout.text>
                        </flux:callout>
                    @enderror
                @endforeach

                {{-- Phones: quantity, total and the button stay in reach at the bottom of the screen. --}}
                <div class="sticky bottom-0 z-10 -mx-4 flex flex-col gap-3 border-t border-line bg-card/95 px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur-sm sm:static sm:mx-0 sm:rounded-2xl sm:border sm:p-4 sm:shadow-warm">
                    <div class="flex items-end gap-4">
                        <flux:select wire:model="quantity" :label="__('Quantity')" class="max-w-28" data-test="quantity">
                            @foreach (range(ModifierValidator::MIN_QUANTITY, ModifierValidator::MAX_QUANTITY) as $n)
                                <flux:select.option :value="$n">{{ $n }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <div class="ms-auto text-end">
                            <flux:text size="sm">{{ __('Total') }}</flux:text>
                            <flux:heading size="xl" data-test="total" x-text="money(totalCents)">{{ $this->displayTotal() }}</flux:heading>
                        </div>
                    </div>
                    @error('quantity')
                        <flux:text class="text-red-600!" data-test="error-quantity">{{ $message }}</flux:text>
                    @enderror

                    <flux:button
                        type="submit"
                        variant="primary"
                        class="h-12! w-full text-base!"
                        icon="shopping-bag"
                        :disabled="! $this->canOrder"
                        wire:loading.attr="disabled"
                        wire:target="addToCart"
                        data-test="add-button"
                    >
                        {{ __('Add to cart') }}
                    </flux:button>
                </div>
            </form>
        </div>
    </div>
</div>
