<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Models\ModifierOption;
use Illuminate\Support\Collection;

/**
 * A drink selection that passed ModifierValidator. Everything here came from the
 * database, not from the request.
 */
final readonly class ValidatedDrink
{
    /**
     * @param  list<int>  $optionIds  sorted ascending
     * @param  Collection<int, ModifierOption>  $options  in display order
     */
    public function __construct(
        public MenuItem $item,
        public array $optionIds,
        public int $quantity,
        public Collection $options,
    ) {}

    /**
     * Identifies "the same drink with the same options", so the cart (T1-4) can
     * merge identical lines. Quantity is not part of it.
     */
    public function key(): string
    {
        return $this->item->id.':'.implode(',', $this->optionIds);
    }
}
