<?php

namespace App\Services;

/**
 * The result of PricingService::price(). Amount field names match the orders table.
 *
 * Always true:
 *   total_cents = subtotal_cents - discount_cents + gst_cents + pst_cents
 *   standard_discount_cents + carbonated_discount_cents = discount_cents
 */
final readonly class PriceBreakdown
{
    /**
     * @param  list<PriceLine>  $lines  in the same order as the drinks passed to price()
     */
    public function __construct(
        public array $lines,
        public int $subtotal_cents,
        /** The discount actually applied: the requested discount, capped at the subtotal. */
        public int $discount_cents,
        public int $gst_cents,
        public int $pst_cents,
        public int $total_cents,
        public int $standard_discount_cents,
        public int $carbonated_discount_cents,
    ) {}
}
