<?php

namespace App\Services;

/**
 * One priced line of a PriceBreakdown. Field names match order_items.
 */
final readonly class PriceLine
{
    public function __construct(
        public string $key,
        public int $menu_item_id,
        public int $unit_price_cents,
        public int $quantity,
        public int $line_total_cents,
        public string $tax_category,
        /** The validated drink this line was priced from, for snapshotting names and options (T1-6). */
        public ValidatedDrink $drink,
    ) {}
}
