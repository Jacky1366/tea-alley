<?php

namespace App\Services;

/**
 * A cart line that no longer passes ModifierValidator. It can't be ordered and isn't priced.
 */
final readonly class InvalidCartLine
{
    /**
     * @param  string|null  $name  the drink's English name, or null if the drink no longer exists
     * @param  list<string>  $reasons  readable sentences, e.g. "Pearls is sold out."
     */
    public function __construct(
        public string $key,
        public int $quantity,
        public ?string $name,
        public array $reasons,
    ) {}
}
