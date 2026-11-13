<?php

use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Services\ModifierValidator;
use App\Services\OrderingWindow;
use App\Support\Money;
use Illuminate\Support\Facades\Storage;
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
     * Client-controlled, so never trusted: addToOrder() ignores the group keys and ModifierValidator
     * checks every id against the database.
     *
     * @var array<array-key, mixed>
     */
    public array $selected = [];

    public int $quantity = 1;

    public bool $confirmed = false;

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
     * Server-rendered starting total, so the price shows before Alpine runs. Display only.
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
     * Validate the selection on the server. Takes no arguments: everything comes from
     * component state, and ModifierValidator re-checks all of it against the database.
     * T1-4 replaces the confirmation with adding to the cart.
     */
    public function addToOrder(): void
    {
        $this->confirmed = false;
        $this->resetErrorBag();

        app(ModifierValidator::class)->validate($this->item, $this->chosenOptionIds(), $this->quantity);

        $this->confirmed = true;
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

<div class="mx-auto flex w-full max-w-2xl flex-col gap-6">
    <div>
        <flux:button :href="route('home')" wire:navigate variant="ghost" size="sm" icon="arrow-left" class="-ms-2">
            {{ __('Back to menu') }}
        </flux:button>
    </div>

    <x-ordering-banner />

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
        @if ($photo = $this->photoUrl())
            <img src="{{ $photo }}" alt="{{ $this->item->name_en }}" class="aspect-square w-full rounded-xl object-cover sm:w-48">
        @else
            <div class="flex aspect-square w-full items-center justify-center rounded-xl bg-zinc-100 text-7xl sm:w-48 dark:bg-zinc-700" role="img" aria-label="{{ __('No photo') }}">
                🧋
            </div>
        @endif

        <div class="flex min-w-0 flex-1 flex-col gap-2">
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="xl" level="1">{{ $this->item->name_en }}</flux:heading>
                @unless ($this->item->is_available)
                    <flux:badge color="red" size="sm" data-test="sold-out">{{ __('Sold out') }} 售完</flux:badge>
                @endunless
            </div>
            <flux:text size="lg">{{ $this->item->name_zh }}</flux:text>
            @if ($this->item->description)
                <flux:text>{{ $this->item->description }}</flux:text>
            @endif
            <flux:heading size="lg" data-test="base-price">{{ $this->money($this->item->price_cents) }}</flux:heading>
        </div>
    </div>

    @error('item')
        <flux:callout variant="danger" icon="x-circle" data-test="error-item">
            <flux:callout.text>{{ $message }}</flux:callout.text>
        </flux:callout>
    @enderror

    <form
        wire:submit="addToOrder"
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

        @foreach ($this->item->modifierGroups as $group)
            @php($single = $group->max_select === 1)
            <fieldset class="flex flex-col gap-2" wire:key="group-{{ $group->id }}" data-test="group-{{ $group->id }}">
                <legend class="mb-2 flex w-full flex-wrap items-baseline justify-between gap-2">
                    <span class="font-medium text-zinc-800 dark:text-white">{{ $group->name_en }} {{ $group->name_zh }}</span>
                    <span class="text-sm text-zinc-500 dark:text-zinc-400">
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

                <div class="flex flex-col divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @if ($single && $group->effectiveMin() === 0)
                        <label class="flex min-h-12 cursor-pointer items-center gap-3 px-4 py-2">
                            <input type="radio" name="group-{{ $group->id }}" value="" wire:model="selected.{{ $group->id }}" class="size-4 accent-[var(--color-accent)]" data-test="option-none-{{ $group->id }}">
                            <span class="flex-1">{{ __('None') }} 不需要</span>
                        </label>
                    @endif

                    @foreach ($group->options as $option)
                        <label
                            wire:key="option-{{ $option->id }}"
                            @class([
                                'flex min-h-12 items-center gap-3 px-4 py-2',
                                'cursor-pointer' => $option->is_available,
                                'cursor-not-allowed opacity-50' => ! $option->is_available,
                            ])
                        >
                            <input
                                type="{{ $single ? 'radio' : 'checkbox' }}"
                                name="group-{{ $group->id }}{{ $single ? '' : '[]' }}"
                                value="{{ $option->id }}"
                                wire:model="selected.{{ $group->id }}"
                                @disabled(! $option->is_available)
                                class="size-4 accent-[var(--color-accent)]"
                                data-test="option-{{ $option->id }}"
                            >
                            <span class="min-w-0 flex-1">
                                {{ $option->name_en }} <span class="text-zinc-500 dark:text-zinc-400">{{ $option->name_zh }}</span>
                                @unless ($option->is_available)
                                    <flux:badge size="sm" color="zinc" class="ms-1">{{ __('Sold out') }}</flux:badge>
                                @endunless
                            </span>
                            @if ($option->price_delta_cents > 0)
                                <span class="shrink-0 text-sm text-zinc-600 dark:text-zinc-300">+{{ $this->money($option->price_delta_cents) }}</span>
                            @endif
                        </label>
                    @endforeach
                </div>

                @foreach ($errors->get('selected.'.$group->id) as $message)
                    <flux:text class="text-red-600! dark:text-red-400!" data-test="error-group-{{ $group->id }}">{{ $message }}</flux:text>
                @endforeach
            </fieldset>
        @endforeach

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
            <flux:text class="text-red-600! dark:text-red-400!" data-test="error-quantity">{{ $message }}</flux:text>
        @enderror

        @if ($this->requiredOptionsSoldOut && $this->item->is_available)
            <flux:callout icon="exclamation-triangle" color="amber" data-test="required-sold-out">
                <flux:callout.text>{{ __('Some required options are sold out') }}</flux:callout.text>
            </flux:callout>
        @endif

        <flux:button type="submit" variant="primary" class="w-full" :disabled="! $this->canOrder" data-test="add-button">
            {{ __('Add to order') }}
        </flux:button>

        @if ($confirmed)
            <flux:callout icon="check-circle" color="green" data-test="confirmed">
                <flux:callout.text>{{ __('Your drink is ready to add. The cart is coming soon.') }}</flux:callout.text>
            </flux:callout>
        @endif
    </form>
</div>
