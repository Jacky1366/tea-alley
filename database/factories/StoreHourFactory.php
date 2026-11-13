<?php

namespace Database\Factories;

use App\Models\StoreHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoreHour>
 */
class StoreHourFactory extends Factory
{
    public function definition(): array
    {
        return [
            'day_of_week' => fake()->unique()->numberBetween(0, 6),
            'opens_at' => '11:00:00',
            'closes_at' => '21:00:00',
            'is_closed' => false,
            'ordering_cutoff_minutes' => 15,
        ];
    }

    public function closed(): static
    {
        return $this->state(['opens_at' => null, 'closes_at' => null, 'is_closed' => true]);
    }
}
