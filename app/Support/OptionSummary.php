<?php

namespace App\Support;

/**
 * Formats chosen options for display with the current language's punctuation:
 * "Size: Large" / "Toppings: Pearls, Pudding" in English, "容量：大杯" / "加料：珍珠、布丁"
 * in Chinese. The separators live in lang/{locale}/formats.php.
 */
final class OptionSummary
{
    /**
     * One group: "Toppings: Pearls, Pudding".
     *
     * @param  list<string>  $options
     */
    public static function line(string $group, array $options): string
    {
        return __('formats.option_group', [
            'group' => $group,
            'options' => implode(self::text('formats.option_separator'), $options),
        ]);
    }

    /**
     * Several groups on one line: "Size: Large · Toppings: Pearls".
     *
     * @param  list<string>  $lines
     */
    public static function join(array $lines): string
    {
        return implode(self::text('formats.group_separator'), $lines);
    }

    private static function text(string $key): string
    {
        $value = __($key);

        return is_string($value) ? $value : '';
    }
}
