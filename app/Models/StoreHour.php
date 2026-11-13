<?php

namespace App\Models;

use Database\Factories\StoreHourFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Opening hours for one weekday. opens_at and closes_at are "HH:MM:SS"
 * strings in America/Vancouver wall-clock time, not UTC.
 */
class StoreHour extends Model
{
    /** @use HasFactory<StoreHourFactory> */
    use HasFactory;

    /** Indexed by day_of_week (0 = Sunday, matching Carbon). */
    public const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    protected $fillable = [
        'day_of_week',
        'opens_at',
        'closes_at',
        'is_closed',
        'ordering_cutoff_minutes',
    ];

    /**
     * Mirrors the database defaults (see Order::$attributes).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_closed' => false,
        'ordering_cutoff_minutes' => 15,
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'is_closed' => 'boolean',
            'ordering_cutoff_minutes' => 'integer',
        ];
    }
}
