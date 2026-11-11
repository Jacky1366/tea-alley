<?php

namespace Database\Factories;

use App\Models\ModifierGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ModifierGroup>
 */
class ModifierGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name_en' => Str::title(fake()->word()),
            'name_zh' => '選項'.fake()->numberBetween(1, 999),
            'is_required' => false,
            'min_select' => 0,
            'max_select' => 1,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }

    public function required(): static
    {
        return $this->state(['is_required' => true, 'min_select' => 1, 'max_select' => 1]);
    }
}
