<?php

namespace App\Services;

use App\Models\MenuItem;
use Illuminate\Contracts\Session\Session;
use Illuminate\Validation\ValidationException;

/**
 * The session cart. It stores only ids and quantities, never prices or names:
 *
 *   session('cart') = [
 *       '12:3,7,9' => ['menu_item_id' => 12, 'option_ids' => [3, 7, 9], 'quantity' => 2],
 *   ]
 *
 * Keys are ValidatedDrink::key(). contents() re-validates every line against the
 * database, so price changes and sold-out drinks show up immediately. The cart never
 * calculates money: price contents()->valid with PricingService.
 *
 * Login keeps the cart (Fortify regenerates the session id but keeps its data);
 * logout clears it (the session is invalidated).
 */
final class Cart
{
    public const SESSION_KEY = 'cart';

    public const MAX_PER_LINE = 10;

    public const MAX_TOTAL = 20;

    public function __construct(
        private Session $session,
        private ModifierValidator $validator,
    ) {}

    /**
     * Add a validated drink, merging with an identical line.
     *
     * @throws ValidationException if the line would exceed 10 or the cart 20 drinks
     */
    public function add(ValidatedDrink $drink): void
    {
        $lines = $this->lines();
        $key = $drink->key();
        $quantity = ($lines[$key]['quantity'] ?? 0) + $drink->quantity;

        $this->ensureWithinLimits($lines, $key, $quantity);

        $lines[$key] = [
            'menu_item_id' => $drink->item->id,
            'option_ids' => $drink->optionIds,
            'quantity' => $quantity,
        ];

        $this->store($lines);
    }

    /**
     * Change a line's quantity. An unknown key does nothing.
     *
     * @throws ValidationException if the quantity is outside 1-10 or the cart would exceed 20 drinks
     */
    public function setQuantity(string $key, int $quantity): void
    {
        $lines = $this->lines();

        if (! array_key_exists($key, $lines)) {
            return;
        }

        if ($quantity < 1 || $quantity > self::MAX_PER_LINE) {
            throw ValidationException::withMessages([
                'quantity' => __('Choose a quantity from 1 to :max.', ['max' => self::MAX_PER_LINE]),
            ]);
        }

        $this->ensureWithinLimits($lines, $key, $quantity);

        $lines[$key]['quantity'] = $quantity;

        $this->store($lines);
    }

    /**
     * Remove a line. Works on the raw session data, so damaged lines can be removed too.
     */
    public function remove(string $key): void
    {
        $raw = $this->rawLines();

        unset($raw[$key]);

        $this->session->put(self::SESSION_KEY, $raw);
    }

    public function clear(): void
    {
        $this->session->forget(self::SESSION_KEY);
    }

    /**
     * Total drinks, for the header badge. Not re-validated, so it includes invalid lines.
     */
    public function count(): int
    {
        return array_sum(array_column($this->lines(), 'quantity'));
    }

    /**
     * Every line re-validated with ModifierValidator against current data. All drinks are
     * loaded in one query. Failures become readable reasons; nothing here throws.
     */
    public function contents(): CartContents
    {
        $lines = $this->lines();

        $items = MenuItem::query()
            ->with(MenuItem::builderRelations())
            ->whereKey(array_column($lines, 'menu_item_id'))
            ->get()
            ->keyBy('id');

        $valid = [];
        $invalid = [];

        foreach ($this->rawLines() as $rawKey => $rawLine) {
            // PHP stores numeric string keys as ints; keep the key as a string so the line stays removable.
            $key = is_string($rawKey) ? $rawKey : strval($rawKey);
            $line = is_string($rawKey) ? $this->parse($rawKey, $rawLine) : null;

            if ($line === null) {
                $invalid[] = new InvalidCartLine($key, $this->rawQuantity($rawLine), null, [__('This item could not be loaded.')]);

                continue;
            }

            $item = $items->get($line['menu_item_id']);

            if ($item === null) {
                $invalid[] = new InvalidCartLine($key, $line['quantity'], null, [__('This drink is no longer on the menu.')]);

                continue;
            }

            try {
                $valid[] = $this->validator->validate($item, $line['option_ids'], $line['quantity']);
            } catch (ValidationException $e) {
                $invalid[] = new InvalidCartLine(
                    $key,
                    $line['quantity'],
                    $item->name_en,
                    array_values(array_unique(array_merge(...array_values($e->errors())))),
                );
            }
        }

        return new CartContents($valid, $invalid);
    }

    /**
     * @param  array<string, array{menu_item_id: int, option_ids: list<int>, quantity: int}>  $lines
     *
     * @throws ValidationException
     */
    private function ensureWithinLimits(array $lines, string $key, int $quantity): void
    {
        if ($quantity > self::MAX_PER_LINE) {
            throw ValidationException::withMessages([
                'quantity' => __('You can add up to :max of the same drink.', ['max' => self::MAX_PER_LINE]),
            ]);
        }

        $others = $this->count() - ($lines[$key]['quantity'] ?? 0);

        if ($others + $quantity > self::MAX_TOTAL) {
            throw ValidationException::withMessages([
                'cart' => __('Your cart can hold up to :max drinks.', ['max' => self::MAX_TOTAL]),
            ]);
        }
    }

    /**
     * Well-formed lines only, keyed by cart key. Damaged entries are skipped here
     * (contents() reports them so the customer can remove them).
     *
     * @return array<string, array{menu_item_id: int, option_ids: list<int>, quantity: int}>
     */
    private function lines(): array
    {
        $lines = [];

        foreach ($this->rawLines() as $key => $rawLine) {
            if (is_string($key) && ($line = $this->parse($key, $rawLine)) !== null) {
                $lines[$key] = $line;
            }
        }

        return $lines;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function rawLines(): array
    {
        $raw = $this->session->get(self::SESSION_KEY, []);

        return is_array($raw) ? $raw : [];
    }

    /**
     * A stored line in its expected shape, or null if it's damaged (wrong types, or a key
     * that doesn't match its ids).
     *
     * @return array{menu_item_id: int, option_ids: list<int>, quantity: int}|null
     */
    private function parse(string $key, mixed $line): ?array
    {
        if (! is_array($line)) {
            return null;
        }

        $itemId = $line['menu_item_id'] ?? null;
        $optionIds = $line['option_ids'] ?? null;
        $quantity = $line['quantity'] ?? null;

        if (! is_int($itemId) || ! is_int($quantity) || ! is_array($optionIds)) {
            return null;
        }

        $ids = [];

        foreach ($optionIds as $id) {
            if (! is_int($id)) {
                return null;
            }

            $ids[] = $id;
        }

        if ($key !== $itemId.':'.implode(',', $ids)) {
            return null;
        }

        return ['menu_item_id' => $itemId, 'option_ids' => $ids, 'quantity' => $quantity];
    }

    private function rawQuantity(mixed $line): int
    {
        return is_array($line) && is_int($line['quantity'] ?? null) ? $line['quantity'] : 0;
    }

    /**
     * @param  array<string, array{menu_item_id: int, option_ids: list<int>, quantity: int}>  $lines
     */
    private function store(array $lines): void
    {
        $this->session->put(self::SESSION_KEY, $lines);
    }
}
