<?php

namespace Database\Factories;

use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ModifierOption>
 */
class ModifierOptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'modifier_group_id' => ModifierGroup::factory(),
            'name_en' => Str::title(fake()->word()),
            'name_zh' => '配料'.fake()->numberBetween(1, 999),
            'price_delta_cents' => fake()->randomElement([0, 50, 75, 100]),
            'is_default' => false,
            'is_available' => true,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }

    public function default(): static
    {
        return $this->state(['is_default' => true, 'price_delta_cents' => 0]);
    }
}
