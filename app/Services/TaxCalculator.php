<?php

namespace App\Services;

use App\Models\MenuItem;
use UnexpectedValueException;

/**
 * GST and PST on a tax base in cents. Integer maths only: rates are basis points
 * from config/tea-alley.php, and each tax rounds half up once with
 * intdiv(cents * bp + 5000, 10000). Rates are read on every call.
 */
final class TaxCalculator
{
    public function gst(int $baseCents): int
    {
        return $this->apply($baseCents, config()->integer('tea-alley.gst_bp'));
    }

    public function pst(int $baseCents): int
    {
        return $this->apply($baseCents, config()->integer('tea-alley.pst_bp'));
    }

    /**
     * Whether PST applies to a tax category. Everything pays GST.
     *
     * @throws UnexpectedValueException for an unknown category, rather than guessing "standard"
     */
    public function chargesPst(string $taxCategory): bool
    {
        return match ($taxCategory) {
            MenuItem::TAX_STANDARD => false,
            MenuItem::TAX_CARBONATED => true,
            default => throw new UnexpectedValueException("Unknown tax category [{$taxCategory}]."),
        };
    }

    /**
     * Round half up: 0.5 cent and above rounds up. Bases are never negative.
     */
    private function apply(int $baseCents, int $basisPoints): int
    {
        return intdiv($baseCents * $basisPoints + 5000, 10000);
    }
}
