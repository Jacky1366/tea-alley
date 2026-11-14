<?php

use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Enums\PickupType;
use App\Models\User;
use App\Services\Cart;
use App\Services\CartContents;
use App\Services\OrderingWindow;
use App\Services\OrderService;
use App\Services\PickupSlots;
use App\Services\PriceBreakdown;
use App\Services\PricingService;
use App\Services\ValidatedDrink;
use App\Support\LocalTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::public')] #[Title('Checkout')] class extends Component {
    /**
     * One per page load. Every submit from this page sends the same key, so a repeated
     * or simultaneous submit gets the same order (OrderService looks it up by user + key).
     */
    #[Locked]
    public string $idempotencyKey = '';

    /** 'asap' or 'scheduled'. Checked against PickupType on submit. */
    public string $pickupType = 'asap';

    /** A slot as a UTC timestamp (Y-m-d\TH:i:s\Z). OrderService checks it against slots it recalculates. */
    public string $pickupSlot = '';

    private const SLOT_FORMAT = 'Y-m-d\TH:i:s\Z';

    public function mount(): void
    {
        $this->idempotencyKey = Str::uuid()->toString();

        if ($this->contents->isEmpty()) {
            $this->redirectRoute('cart', navigate: true);
        } elseif ($this->contents->hasInvalid()) {
            session()->flash('checkout-status', __('Some items in your cart can no longer be ordered. Remove them to check out.'));

            $this->redirectRoute('cart', navigate: true);
        }
    }

    /**
     * Every line re-validated against the database on each request.
     */
    #[Computed]
    public function contents(): CartContents
    {
        return app(Cart::class)->contents();
    }

    /**
     * PricingService is the only place amounts are calculated. OrderService prices the
     * order again inside its transaction; these are the same numbers unless data changed.
     */
    #[Computed]
    public function breakdown(): PriceBreakdown
    {
        return app(PricingService::class)->price($this->contents->valid);
    }

    #[Computed]
    public function orderingOpen(): bool
    {
        return app(OrderingWindow::class)->isOpen(now());
    }

    #[Computed]
    public function maxPrepMinutes(): int
    {
        return max([0, ...array_map(fn (ValidatedDrink $drink): int => $drink->item->prep_minutes, $this->contents->valid)]);
    }

    #[Computed]
    public function asapEstimate(): CarbonImmutable
    {
        return app(PickupSlots::class)->asapEstimate(now(), $this->maxPrepMinutes);
    }

    /**
     * @return list<CarbonImmutable>
     */
    #[Computed]
    public function pickupSlots(): array
    {
        return app(PickupSlots::class)->slots(now(), $this->maxPrepMinutes);
    }

    public function slotValue(CarbonImmutable $slot): string
    {
        return $slot->utc()->format(self::SLOT_FORMAT);
    }

    /**
     * Takes no arguments. The type and slot are public properties, so they are parsed
     * strictly here and OrderService checks the slot again; prices never come from the client.
     */
    public function placeOrder(): void
    {
        $this->resetErrorBag();

        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $type = PickupType::tryFrom($this->pickupType);

        if ($type === null) {
            throw ValidationException::withMessages(['pickup' => __('Choose a pickup option.')]);
        }

        $order = app(OrderService::class)->place(
            $user,
            $this->contents,
            $type,
            $type === PickupType::Scheduled ? $this->parseSlot() : null,
            $this->idempotencyKey,
        );

        // A repeated submit returns the already-paid order; the gateway doesn't charge it again.
        $url = app(PaymentGateway::class)->startPayment($order);

        // Empty the cart only once payment has succeeded.
        if ($order->refresh()->status === OrderStatus::Paid) {
            app(Cart::class)->clear();
            $this->dispatch('cart-updated');
        }

        $this->redirect($url);
    }

    public function money(int $cents): string
    {
        return Money::format($cents);
    }

    /**
     * @throws ValidationException if the value isn't a well-formed slot timestamp
     */
    private function parseSlot(): CarbonImmutable
    {
        $slot = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $this->pickupSlot) === 1
            ? CarbonImmutable::createFromFormat(self::SLOT_FORMAT, $this->pickupSlot, 'UTC')
            : null;

        // The round trip rejects values PHP would silently roll over, like Feb 31.
        if (! $slot instanceof CarbonImmutable || $slot->format(self::SLOT_FORMAT) !== $this->pickupSlot) {
            throw ValidationException::withMessages(['pickup' => __('Choose a pickup time.')]);
        }

        return $slot;
    }
}; ?>

<div class="mx-auto flex w-full max-w-2xl flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Checkout') }}</flux:heading>
    </div>

    <x-ordering-banner />

    @foreach (['cart', 'ordering', 'order'] as $errorKey)
        @error($errorKey)
            <flux:callout variant="danger" icon="x-circle" data-test="error-{{ $errorKey }}">
                @foreach ($errors->get($errorKey) as $message)
                    <flux:callout.text>{{ $message }}</flux:callout.text>
                @endforeach
                @if ($errorKey === 'cart')
                    <flux:callout.text>
                        <flux:link :href="route('cart')" wire:navigate>{{ __('Review your cart') }}</flux:link>
                    </flux:callout.text>
                @endif
            </flux:callout>
        @enderror
    @endforeach

    <ul class="flex flex-col divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="checkout-lines">
        @foreach ($this->breakdown->lines as $line)
            <li class="flex items-start justify-between gap-3 p-4" wire:key="line-{{ $line->key }}" data-test="checkout-line">
                <div class="min-w-0">
                    <flux:heading>{{ __(':drink × :quantity', ['drink' => $line->drink->item->localizedName(), 'quantity' => $line->quantity]) }}</flux:heading>
                    @if ($summary = $line->drink->optionSummary())
                        <flux:text size="sm" class="mt-1">{{ $summary }}</flux:text>
                    @endif
                    <flux:text size="sm" class="mt-1">{{ __(':price each', ['price' => $this->money($line->unit_price_cents)]) }}</flux:text>
                </div>
                <flux:heading class="shrink-0" data-test="line-total">{{ $this->money($line->line_total_cents) }}</flux:heading>
            </li>
        @endforeach
    </ul>

    <dl class="flex flex-col gap-1" data-test="totals">
        <div class="flex justify-between">
            <dt><flux:text>{{ __('Subtotal') }}</flux:text></dt>
            <dd><flux:text data-test="subtotal">{{ $this->money($this->breakdown->subtotal_cents) }}</flux:text></dd>
        </div>
        <div class="flex justify-between">
            <dt><flux:text>{{ __('GST') }}</flux:text></dt>
            <dd><flux:text data-test="gst">{{ $this->money($this->breakdown->gst_cents) }}</flux:text></dd>
        </div>
        <div class="flex justify-between">
            <dt><flux:text>{{ __('PST') }}</flux:text></dt>
            <dd><flux:text data-test="pst">{{ $this->money($this->breakdown->pst_cents) }}</flux:text></dd>
        </div>
        <div class="mt-1 flex items-baseline justify-between">
            <dt><flux:heading size="lg">{{ __('Total') }}</flux:heading></dt>
            <dd><flux:heading size="lg" data-test="total">{{ $this->money($this->breakdown->total_cents) }}</flux:heading></dd>
        </div>
    </dl>

    <div class="flex flex-col gap-4">
        <flux:radio.group wire:model.live="pickupType" :label="__('Pickup')" data-test="pickup-type">
            <flux:radio
                value="asap"
                :label="__('As soon as possible')"
                :description="__('Ready around :time', ['time' => LocalTime::format($this->asapEstimate, 'time')])"
                data-test="pickup-asap"
            />
            @if ($this->pickupSlots !== [])
                <flux:radio value="scheduled" :label="__('Later today')" data-test="pickup-scheduled" />
            @endif
        </flux:radio.group>

        @if ($pickupType === 'scheduled' && $this->pickupSlots !== [])
            <flux:select wire:model="pickupSlot" :label="__('Pickup time')" data-test="pickup-slot">
                <flux:select.option value="">{{ __('Choose a time') }}</flux:select.option>
                @foreach ($this->pickupSlots as $slot)
                    <flux:select.option value="{{ $this->slotValue($slot) }}" wire:key="slot-{{ $this->slotValue($slot) }}">{{ LocalTime::format($slot, 'time') }}</flux:select.option>
                @endforeach
            </flux:select>
        @elseif ($pickupType === 'scheduled')
            <flux:text data-test="no-slots">{{ __('No pickup times are left today. Choose as soon as possible.') }}</flux:text>
        @endif

        @error('pickup')
            <flux:text class="text-red-600! dark:text-red-400!" data-test="error-pickup">{{ $message }}</flux:text>
        @enderror
    </div>

    <flux:button
        variant="primary"
        class="w-full"
        wire:click="placeOrder"
        wire:loading.attr="disabled"
        wire:target="placeOrder"
        :disabled="! $this->orderingOpen"
        data-test="place-order"
    >
        {{ __('Place order · :total', ['total' => $this->money($this->breakdown->total_cents)]) }}
    </flux:button>
</div>
