<?php

namespace Database\Factories;

use App\Enums\PromoType;
use App\Models\PromoCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PromoCode>
 */
class PromoCodeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => Str::upper(Str::random(8)),
            'type' => PromoType::Percent,
            'value' => fake()->randomElement([10, 15, 20]),
            'expires_at' => null,
            'max_uses' => null,
            'used_count' => 0,
            'is_active' => true,
        ];
    }

    public function fixed(int $cents = 200): static
    {
        return $this->state(['type' => PromoType::Fixed, 'value' => $cents]);
    }
}
