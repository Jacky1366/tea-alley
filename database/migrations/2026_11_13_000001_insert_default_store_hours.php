<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The store hours admin can only edit the 7 weekday rows, not create them.
 * Insert one closed row per weekday so a fresh database has all 7 and stays
 * closed until a manager sets real hours. insertOrIgnore leaves any existing
 * rows untouched (day_of_week is unique).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('store_hours')->insertOrIgnore(
            collect(range(0, 6))->map(fn (int $day): array => [
                'day_of_week' => $day,
                'opens_at' => null,
                'closes_at' => null,
                'is_closed' => true,
                'ordering_cutoff_minutes' => 15,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
        );
    }

    public function down(): void
    {
        // Intentionally empty: by now these rows may hold real hours set by a
        // manager, and rolling back must not delete them.
    }
};
