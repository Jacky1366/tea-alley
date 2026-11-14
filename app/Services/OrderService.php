<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PickupType;
use App\Models\MenuItem;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Turns a cart into a pending_payment order. Nothing from the client is trusted: the
 * cart contributes only ids and quantities, and every drink, option, price, and pickup
 * time is re-read or recalculated here.
 *
 * Error keys: cart, ordering, pickup, order.
 */
final class OrderService
{
    public const MAX_NUMBER_ATTEMPTS = 5;

    /** How often the whole transaction is retried after a deadlock. */
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private ModifierValidator $validator,
        private PricingService $pricing,
        private OrderingWindow $window,
        private PickupSlots $slots,
        private OrderNumberGenerator $numbers,
    ) {}

    /**
     * Place the order, or return the order this user already placed with this key.
     *
     * Inside one transaction:
     *   1. lock the cart's menu_items, then its modifier_options (id order, so two
     *      checkouts can't deadlock). These are the first statements, so MySQL's
     *      REPEATABLE READ snapshot for the plain reads below is taken after the locks.
     *   2. re-validate every line with ModifierValidator
     *   3. check the ordering window
     *   4. check the pickup time against slots recalculated now (ASAP: the estimate)
     *   5. price with PricingService (no discount until T2-7)
     *   6. insert the order with a random number, retrying collisions
     *   7. insert order_items and order_item_modifiers from the priced lines
     * Any failure rolls everything back.
     *
     * @param  CarbonInterface|null  $pickupAt  required for a scheduled pickup, ignored for ASAP
     *
     * @throws ValidationException
     */
    public function place(User $user, CartContents $cart, PickupType $type, ?CarbonInterface $pickupAt, string $idempotencyKey): Order
    {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 64) {
            throw new InvalidArgumentException('The idempotency key must be 1 to 64 characters.');
        }

        // Before the cart checks: a repeated submit must find its order even after payment emptied the cart.
        $existing = $this->existingOrder($user, $idempotencyKey);

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(
            fn (): Order => $this->placeInTransaction($user, $cart, $type, $pickupAt, $idempotencyKey),
            self::TRANSACTION_ATTEMPTS,
        );
    }

    /**
     * @throws ValidationException
     */
    private function placeInTransaction(User $user, CartContents $cart, PickupType $type, ?CarbonInterface $pickupAt, string $idempotencyKey): Order
    {
        if ($cart->isEmpty()) {
            throw ValidationException::withMessages(['cart' => __('Your cart is empty.')]);
        }

        if ($cart->hasInvalid()) {
            throw ValidationException::withMessages(['cart' => __('Some items in your cart can no longer be ordered. Review your cart.')]);
        }

        $items = $this->lockRows($cart);
        $drinks = $this->revalidate($cart, $items);

        $now = now();

        if (! $this->window->isOpen($now)) {
            throw ValidationException::withMessages(['ordering' => __('Online ordering is closed right now.')]);
        }

        $pickupAt = $this->resolvePickup($type, $pickupAt, $now, $drinks);
        $breakdown = $this->pricing->price($drinks);

        $order = $this->insertOrder($user, $idempotencyKey, [
            'user_id' => $user->id,
            'status' => OrderStatus::PendingPayment,
            'pickup_type' => $type,
            'pickup_at' => $pickupAt->setTimezone(config()->string('app.timezone')), // stored in UTC
            'subtotal_cents' => $breakdown->subtotal_cents,
            'discount_cents' => $breakdown->discount_cents,
            'gst_cents' => $breakdown->gst_cents,
            'pst_cents' => $breakdown->pst_cents,
            'total_cents' => $breakdown->total_cents,
            'idempotency_key' => $idempotencyKey,
        ]);

        // A simultaneous submit with the same key won the race: return its order, write nothing.
        if (! $order->wasRecentlyCreated) {
            return $order;
        }

        foreach ($breakdown->lines as $line) {
            $this->snapshotLine($order, $line);
        }

        // Strict mode: reload so nullable columns we didn't set can be read.
        return $order->refresh();
    }

    private function existingOrder(User $user, string $idempotencyKey): ?Order
    {
        return Order::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * Lock the cart's drinks and options FOR UPDATE, then load the builder relations.
     *
     * @return Collection<int, MenuItem> keyed by id
     */
    private function lockRows(CartContents $cart): Collection
    {
        $itemIds = [];
        $optionIds = [];

        foreach ($cart->valid as $drink) {
            $itemIds[] = $drink->item->id;
            array_push($optionIds, ...$drink->optionIds);
        }

        $items = MenuItem::query()->whereKey(array_unique($itemIds))->orderBy('id')->lockForUpdate()->get();

        if ($optionIds !== []) {
            ModifierOption::query()->whereKey(array_unique($optionIds))->orderBy('id')->lockForUpdate()->get(['id']);
        }

        return $items->load(MenuItem::builderRelations())->keyBy('id');
    }

    /**
     * Every line validated again against the locked rows. All problems are collected
     * into one readable "cart" error.
     *
     * @param  Collection<int, MenuItem>  $items  keyed by id
     * @return list<ValidatedDrink>
     *
     * @throws ValidationException
     */
    private function revalidate(CartContents $cart, Collection $items): array
    {
        $drinks = [];
        $problems = [];

        foreach ($cart->valid as $line) {
            $item = $items->get($line->item->id);

            if ($item === null) {
                $problems[] = __(':drink is no longer on the menu.', ['drink' => $line->item->localizedName()]);

                continue;
            }

            try {
                $drinks[] = $this->validator->validate($item, $line->optionIds, $line->quantity);
            } catch (ValidationException $e) {
                foreach (array_unique(array_merge(...array_values($e->errors()))) as $reason) {
                    $problems[] = __(':drink: :reason', ['drink' => $item->localizedName(), 'reason' => $reason]);
                }
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages(['cart' => $problems]);
        }

        return $drinks;
    }

    /**
     * @param  list<ValidatedDrink>  $drinks  not empty
     *
     * @throws ValidationException
     */
    private function resolvePickup(PickupType $type, ?CarbonInterface $pickupAt, CarbonInterface $now, array $drinks): CarbonImmutable
    {
        $maxPrep = max(0, ...array_map(fn (ValidatedDrink $drink): int => $drink->item->prep_minutes, $drinks));

        if ($type === PickupType::Asap) {
            return $this->slots->asapEstimate($now, $maxPrep);
        }

        if ($pickupAt === null) {
            throw ValidationException::withMessages(['pickup' => __('Choose a pickup time.')]);
        }

        if (! $this->slots->isAvailable($pickupAt, $now, $maxPrep)) {
            throw ValidationException::withMessages(['pickup' => __('That pickup time is no longer available. Choose another.')]);
        }

        return CarbonImmutable::instance($pickupAt);
    }

    /**
     * Insert the order with a fresh number. Each attempt runs in a savepoint, so a unique
     * violation undoes only that insert. A locking read then tells the two unique indexes
     * apart (it sees committed rows, not this transaction's snapshot):
     *   - this user already has an order with this key: return it (wasRecentlyCreated is false)
     *   - otherwise the order number collided: try another number
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException after MAX_NUMBER_ATTEMPTS collisions
     */
    private function insertOrder(User $user, string $idempotencyKey, array $attributes): Order
    {
        for ($attempt = 1; $attempt <= self::MAX_NUMBER_ATTEMPTS; $attempt++) {
            $number = $this->numbers->next();

            try {
                return DB::transaction(fn (): Order => Order::query()->create(
                    [...$attributes, 'order_number' => $number],
                ));
            } catch (UniqueConstraintViolationException) {
                $existing = Order::query()
                    ->where('user_id', $user->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }
        }

        throw ValidationException::withMessages(['order' => __("We couldn't place your order. Please try again.")]);
    }

    /**
     * Snapshot one priced line: names, tax category, and prices as they are right now.
     */
    private function snapshotLine(Order $order, PriceLine $line): void
    {
        $item = $line->drink->item;

        $orderItem = $order->items()->create([
            'menu_item_id' => $item->id,
            'name_en' => $item->name_en,
            'name_zh' => $item->name_zh,
            'tax_category' => $line->tax_category,
            'unit_price_cents' => $line->unit_price_cents,
            'quantity' => $line->quantity,
        ]);

        foreach ($line->drink->options as $option) {
            $group = $item->modifierGroups->firstOrFail('id', $option->modifier_group_id);

            $orderItem->modifiers()->create([
                'modifier_option_id' => $option->id,
                'group_name_en' => $group->name_en,
                'group_name_zh' => $group->name_zh,
                'name_en' => $option->name_en,
                'name_zh' => $option->name_zh,
                'price_delta_cents' => $option->price_delta_cents,
            ]);
        }
    }
}
