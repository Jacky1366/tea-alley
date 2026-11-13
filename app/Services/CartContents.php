<?php

namespace App\Services;

/**
 * The cart re-validated against the database. Price the valid lines with PricingService.
 */
final readonly class CartContents
{
    /**
     * @param  list<ValidatedDrink>  $valid  in cart order
     * @param  list<InvalidCartLine>  $invalid  in cart order
     */
    public function __construct(
        public array $valid,
        public array $invalid,
    ) {}

    public function isEmpty(): bool
    {
        return $this->valid === [] && $this->invalid === [];
    }

    public function hasInvalid(): bool
    {
        return $this->invalid !== [];
    }
}
