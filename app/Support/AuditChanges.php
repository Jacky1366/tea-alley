<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Support\Str;

/**
 * Turns an audit log entry's stored values into readable lines for the admin table:
 * "Price: $5.95 → $6.50", "Available: Yes → No". Display only.
 */
final class AuditChanges
{
    /** Columns stored in cents, shown as money. */
    private const MONEY = ['price_cents', 'price_delta_cents'];

    private const LABELS = [
        'name_en' => 'Name (EN)',
        'name_zh' => 'Name (ZH)',
        'price_cents' => 'Price',
        'price_delta_cents' => 'Price change',
        'is_available' => 'Available',
        'is_default' => 'Default',
        'category_id' => 'Category ID',
        'modifier_group_id' => 'Group ID',
        'photo_path' => 'Photo',
        'tax_category' => 'Tax category',
        'prep_minutes' => 'Prep minutes',
        'sort_order' => 'Sort order',
    ];

    /**
     * One line per changed column for an update ("Price: $5.95 → $6.50"), or the deleted
     * record's values for a delete ("Price: $5.95").
     *
     * @return list<string>
     */
    public static function lines(AuditLog $entry): array
    {
        $old = $entry->old_values ?? [];
        $new = $entry->new_values ?? [];
        $lines = [];

        foreach ($old + $new as $key => $unused) {
            $label = self::label($key);

            $lines[] = $entry->event === AuditLog::UPDATED
                ? $label.': '.self::value($key, $old[$key] ?? null).' → '.self::value($key, $new[$key] ?? null)
                : $label.': '.self::value($key, $old[$key] ?? null);
        }

        return $lines;
    }

    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? Str::headline($key);
    }

    public static function value(string $key, mixed $value): string
    {
        return match (true) {
            $value === null, $value === '' => '—',
            in_array($key, self::MONEY, true) && is_int($value) => Money::format($value),
            is_bool($value) => $value ? 'Yes' : 'No',
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_UNICODE) ?: '?',
        };
    }
}
