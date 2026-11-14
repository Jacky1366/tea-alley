<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Models\ModifierOption;
use App\Support\OptionSummary;
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

    /**
     * "Size: Large · Toppings: Pearls, Pudding" (or "容量：大杯 · 加料：珍珠、布丁"), in
     * display order and the current language, for the cart and checkout pages. Uses the
     * relations ModifierValidator already loaded, so nothing is lazy loaded.
     */
    public function optionSummary(): string
    {
        $lines = [];

        foreach ($this->item->modifierGroups as $group) {
            $names = [];

            foreach ($group->options as $option) {
                if (in_array($option->id, $this->optionIds, true)) {
                    $names[] = $option->localizedName();
                }
            }

            if ($names !== []) {
                $lines[] = OptionSummary::line($group->localizedName(), $names);
            }
        }

        return OptionSummary::join($lines);
    }
}
