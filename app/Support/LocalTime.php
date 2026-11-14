<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Formats a time for display: converted to the display timezone (America/Vancouver) and
 * written in the current language. The patterns themselves are translations
 * (lang/{locale}/formats.php), because Chinese also changes the word order:
 * "Fri 11:00 AM" is "週五 上午 11:00", not "週五 11:00 上午".
 */
final class LocalTime
{
    /**
     * @param  'time'|'day_time'|'date_time'|'day_date_time'  $pattern
     */
    public static function format(CarbonInterface $time, string $pattern): string
    {
        $format = __('formats.'.$pattern);

        return CarbonImmutable::instance($time)
            ->setTimezone(config()->string('app.display_timezone'))
            ->settings(['locale' => app()->getLocale()])
            ->translatedFormat(is_string($format) ? $format : 'Y-m-d H:i');
    }
}
