<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Pickup times for a checkout, in the display timezone (config app.display_timezone).
 *
 *   ASAP estimate:  now + the longest prep_minutes in the cart
 *   Scheduled:      same-day 15-minute slots. The earliest is the ASAP estimate rounded
 *                   up to the next 15-minute mark (a time already on a mark stays); the
 *                   latest is at or before OrderingWindow::orderingEndsAt().
 *
 * Rounding happens in local time, using the timezone database (no fixed offsets).
 * $now is always passed in; nothing here reads the clock.
 */
final class PickupSlots
{
    public const INTERVAL_MINUTES = 15;

    public function __construct(private OrderingWindow $window) {}

    public function asapEstimate(CarbonInterface $now, int $maxPrepMinutes): CarbonImmutable
    {
        return $this->toLocal($now)->addMinutes($maxPrepMinutes);
    }

    /**
     * Empty when ordering is closed or no slot fits before ordering ends.
     *
     * @return list<CarbonImmutable>
     */
    public function slots(CarbonInterface $now, int $maxPrepMinutes): array
    {
        $endsAt = $this->window->orderingEndsAt($now);

        if ($endsAt === null) {
            return [];
        }

        $slots = [];
        $slot = $this->roundUp($this->asapEstimate($now, $maxPrepMinutes));

        while ($slot->lte($endsAt)) {
            $slots[] = $slot;
            $slot = $slot->addMinutes(self::INTERVAL_MINUTES);
        }

        return $slots;
    }

    /**
     * Whether $pickupAt is exactly one of the slots right now.
     */
    public function isAvailable(CarbonInterface $pickupAt, CarbonInterface $now, int $maxPrepMinutes): bool
    {
        foreach ($this->slots($now, $maxPrepMinutes) as $slot) {
            if ($slot->equalTo($pickupAt)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Up to the next 15-minute mark of the local wall clock. Seconds and microseconds count:
     * 14:15:20 becomes 14:30, 14:15:00 stays.
     */
    private function roundUp(CarbonImmutable $time): CarbonImmutable
    {
        $mark = $time->startOfHour()->addMinutes(intdiv($time->minute, self::INTERVAL_MINUTES) * self::INTERVAL_MINUTES);

        return $mark->lt($time) ? $mark->addMinutes(self::INTERVAL_MINUTES) : $mark;
    }

    private function toLocal(CarbonInterface $now): CarbonImmutable
    {
        return CarbonImmutable::instance($now)->setTimezone(config()->string('app.display_timezone'));
    }
}
