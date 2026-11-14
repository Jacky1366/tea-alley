<?php

namespace App\Services;

/**
 * Order numbers: "TA-" plus 6 random digits. Not guaranteed unique; OrderService
 * retries on a collision. Tests bind a fixed sequence to simulate collisions.
 */
class OrderNumberGenerator
{
    public function next(): string
    {
        return 'TA-'.str_pad(strval(random_int(0, 999999)), 6, '0', STR_PAD_LEFT);
    }
}
