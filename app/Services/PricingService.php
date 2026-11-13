<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * The only place that calculates an amount that is stored or charged. The cart (T1-4),
 * checkout (T1-6), and Stripe (T2-2) all use it.
 *
 * Pure: no database queries and no clock. Every price comes from the models inside
 * each ValidatedDrink, which ModifierValidator loaded from the database.
 * Integer cents only: no division operator, no floats.
 */
final class PricingService
{
    public function __construct(private TaxCalculator $tax) {}

    /**
     * @param  list<ValidatedDrink>  $drinks
     * @param  int  $discountCents  from T2-7; capped at the subtotal
     *
     * @throws InvalidArgumentException if the discount is negative
     * @throws \UnexpectedValueException if a drink has an unknown tax category
     */
    public function price(array $drinks, int $discountCents = 0): PriceBreakdown
    {
        if ($discountCents < 0) {
            throw new InvalidArgumentException('The discount cannot be negative.');
        }

        $lines = [];
        $subtotal = 0;
        $carbonatedSubtotal = 0;

        foreach ($drinks as $drink) {
            $unit = $drink->item->price_cents;

            foreach ($drink->options as $option) {
                $unit += $option->price_delta_cents;
            }

            $lineTotal = $unit * $drink->quantity;
            $category = $drink->item->tax_category;

            $subtotal += $lineTotal;

            if ($this->tax->chargesPst($category)) {
                $carbonatedSubtotal += $lineTotal;
            }

            $lines[] = new PriceLine(
                key: $drink->key(),
                menu_item_id: $drink->item->id,
                unit_price_cents: $unit,
                quantity: $drink->quantity,
                line_total_cents: $lineTotal,
                tax_category: $category,
                drink: $drink,
            );
        }

        $discount = min($discountCents, $subtotal);
        $carbonatedDiscount = $this->carbonatedShare($discount, $carbonatedSubtotal, $subtotal);
        $standardDiscount = $discount - $carbonatedDiscount;

        // Discount first, then tax. Each tax is calculated and rounded once for the whole order.
        $gst = $this->tax->gst($subtotal - $discount);
        $pst = $this->tax->pst($carbonatedSubtotal - $carbonatedDiscount);

        return new PriceBreakdown(
            lines: $lines,
            subtotal_cents: $subtotal,
            discount_cents: $discount,
            gst_cents: $gst,
            pst_cents: $pst,
            total_cents: $subtotal - $discount + $gst + $pst,
            standard_discount_cents: $standardDiscount,
            carbonated_discount_cents: $carbonatedDiscount,
        );
    }

    /**
     * The carbonated lines' share of the discount, in proportion to their subtotal:
     * discount * carbonatedSubtotal / subtotal, rounded half up with integers only
     * (floor(a / b + 1/2) = intdiv(2a + b, 2b)). The standard lines get the rest,
     * so the two shares always add up to the discount.
     */
    private function carbonatedShare(int $discount, int $carbonatedSubtotal, int $subtotal): int
    {
        if ($subtotal === 0) {
            return 0;
        }

        return intdiv(2 * $discount * $carbonatedSubtotal + $subtotal, 2 * $subtotal);
    }
}
