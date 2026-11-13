<?php

namespace App\Services;

use App\Models\StoreHour;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Decides when online ordering is open, based on store_hours.
 *
 * A day's ordering window is opens_at <= now < closes_at - ordering_cutoff_minutes,
 * evaluated in the display timezone (config app.display_timezone). Windows are
 * built from the local date plus wall-clock times, so DST days come out right.
 *
 * A day has no window if it is marked closed, its row is missing, a time is
 * missing, or the cutoff leaves no time to order. Overnight hours are not supported.
 *
 * $now is always passed in; nothing here reads the clock. Nothing is cached:
 * every call reads store_hours fresh.
 */
final class OrderingWindow
{
    /**
     * How many days after today nextOpening() looks. 7 includes the same
     * weekday next week, so a store open one day a week is still found.
     */
    private const LOOKAHEAD_DAYS = 7;

    public function isOpen(CarbonInterface $now): bool
    {
        return $this->orderingEndsAt($now) !== null;
    }

    /**
     * When the current ordering window ends (closes_at minus the cutoff), in the
     * display timezone. Null when ordering is not open right now.
     */
    public function orderingEndsAt(CarbonInterface $now): ?CarbonImmutable
    {
        $local = $this->toLocal($now);
        $window = $this->windowOn($local->startOfDay(), $this->hoursByDay());

        if ($window === null || $local->lt($window['opens']) || $local->gte($window['ends'])) {
            return null;
        }

        return $window['ends'];
    }

    /**
     * When the next ordering window starts, in the display timezone.
     *
     * - Ordering open right now: returns $now (converted to the display timezone).
     * - Otherwise: the start of the next window, today or within the next 7 days.
     * - No window today or in the next 7 days: returns null.
     */
    public function nextOpening(CarbonInterface $now): ?CarbonImmutable
    {
        $local = $this->toLocal($now);
        $hours = $this->hoursByDay();

        for ($offset = 0; $offset <= self::LOOKAHEAD_DAYS; $offset++) {
            $window = $this->windowOn($local->startOfDay()->addDays($offset), $hours);

            if ($window === null || $local->gte($window['ends'])) {
                continue;
            }

            return $local->lt($window['opens']) ? $window['opens'] : $local;
        }

        return null;
    }

    /**
     * @param  Collection<int, StoreHour>  $hours  keyed by day_of_week
     * @return array{opens: CarbonImmutable, ends: CarbonImmutable}|null
     */
    private function windowOn(CarbonImmutable $date, Collection $hours): ?array
    {
        $day = $hours->get($date->dayOfWeek);

        if ($day === null || $day->is_closed || $day->opens_at === null || $day->closes_at === null) {
            return null;
        }

        $opens = $date->setTimeFromTimeString($day->opens_at);
        $ends = $date->setTimeFromTimeString($day->closes_at)->subMinutes($day->ordering_cutoff_minutes);

        return $ends->gt($opens) ? ['opens' => $opens, 'ends' => $ends] : null;
    }

    /**
     * @return Collection<int, StoreHour>
     */
    private function hoursByDay(): Collection
    {
        return StoreHour::query()->get()->keyBy('day_of_week');
    }

    private function toLocal(CarbonInterface $now): CarbonImmutable
    {
        return CarbonImmutable::instance($now)->setTimezone(config()->string('app.display_timezone'));
    }
}
