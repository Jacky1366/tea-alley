<?php

namespace Database\Seeders;

use App\Models\StoreHour;
use Illuminate\Database\Seeder;

class StoreHoursSeeder extends Seeder
{
    /**
     * Seed opening hours for each weekday, in America/Vancouver wall-clock time.
     * 0 = Sunday ... 6 = Saturday, matching Carbon's dayOfWeek.
     */
    public function run(): void
    {
        $hours = [
            0 => ['12:00:00', '20:00:00'],
            1 => ['11:00:00', '21:00:00'],
            2 => ['11:00:00', '21:00:00'],
            3 => ['11:00:00', '21:00:00'],
            4 => ['11:00:00', '21:00:00'],
            5 => ['11:00:00', '22:00:00'],
            6 => ['11:00:00', '22:00:00'],
        ];

        foreach ($hours as $day => [$opensAt, $closesAt]) {
            StoreHour::updateOrCreate(
                ['day_of_week' => $day],
                [
                    'opens_at' => $opensAt,
                    'closes_at' => $closesAt,
                    'is_closed' => false,
                    'ordering_cutoff_minutes' => 15,
                ],
            );
        }
    }
}
