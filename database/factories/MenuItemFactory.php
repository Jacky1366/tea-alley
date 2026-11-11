<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MenuItem>
 */
class MenuItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'name_en' => Str::title(fake()->word().' '.fake()->word().' '.fake()->word()),
            'name_zh' => '飲品'.fake()->numberBetween(1, 999),
            'description' => fake()->optional()->sentence(),
            'price_cents' => fake()->numberBetween(450, 800),
            'photo_path' => null,
            'tax_category' => MenuItem::TAX_STANDARD,
            'prep_minutes' => 3,
            'is_available' => true,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }

    public function carbonated(): static
    {
        return $this->state(['tax_category' => MenuItem::TAX_CARBONATED]);
    }

    public function unavailable(): static
    {
        return $this->state(['is_available' => false]);
    }
}
