<?php

use App\Models\StoreHour;
use App\Services\OrderingWindow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Reads store_hours, so it needs the app and a migrated database.
uses(TestCase::class, RefreshDatabase::class);

/*
 * Fixture week, cutoff 15 minutes. Times are America/Vancouver wall clock.
 *   Sun        12:00-20:00  (ordering until 19:45)
 *   Mon, Tue   closed
 *   Wed-Sat    11:00-21:00  (ordering until 20:45)
 *
 * Dates used: Sun Nov 15 ... Sat Nov 21, 2026.
 *
 * Vancouver tests never hard-code a UTC offset. BC moved to permanent daylight time
 * after Mar 8, 2026 (tzdata 2026.x), and CI and local machines may have different
 * tzdata versions. Expected instants come from the timezone database instead, so
 * the tests check the service follows local wall-clock time whatever the offset is.
 */
beforeEach(function () {
    setStoreHours([
        0 => ['12:00:00', '20:00:00'],
        1 => null,
        2 => null,
        3 => ['11:00:00', '21:00:00'],
        4 => ['11:00:00', '21:00:00'],
        5 => ['11:00:00', '21:00:00'],
        6 => ['11:00:00', '21:00:00'],
    ]);

    $this->window = new OrderingWindow;
});

function vancouver(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'America/Vancouver');
}

function utc(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'UTC');
}

describe('isOpen', function () {
    test('follows the ordering window', function (string $now, bool $open) {
        expect($this->window->isOpen(vancouver($now)))->toBe($open);
    })->with([
        'before opening' => ['2026-11-18 10:59:59', false],
        'exactly at opening' => ['2026-11-18 11:00:00', true],
        'mid-day' => ['2026-11-18 15:00:00', true],
        'last second before the cutoff' => ['2026-11-18 20:44:59', true],
        'exactly at the cutoff minute' => ['2026-11-18 20:45:00', false],
        'after the cutoff, before closing' => ['2026-11-18 20:50:00', false],
        'exactly at closing' => ['2026-11-18 21:00:00', false],
        'after closing' => ['2026-11-18 22:30:00', false],
        'closed day (Monday)' => ['2026-11-16 15:00:00', false],
        'early morning' => ['2026-11-18 00:30:00', false],
    ]);

    test('uses Vancouver time even when $now is in UTC', function () {
        // Wed 15:00 in Vancouver, passed as a UTC instant: open.
        // Wed 23:30 in Vancouver is already Thursday in UTC, but the store is judged on Vancouver's Wednesday: closed.
        $afternoon = vancouver('2026-11-18 15:00:00')->utc();
        $lateNight = vancouver('2026-11-18 23:30:00')->utc();

        expect($lateNight->dayOfWeek)->toBe(4)
            ->and($this->window->isOpen($afternoon))->toBeTrue()
            ->and($this->window->isOpen($lateNight))->toBeFalse();
    });

    test('a day with a missing row is closed', function () {
        StoreHour::where('day_of_week', 3)->delete();

        expect($this->window->isOpen(vancouver('2026-11-18 15:00:00')))->toBeFalse();
    });

    test('a day whose cutoff leaves no time to order is closed', function () {
        StoreHour::where('day_of_week', 3)->update(['ordering_cutoff_minutes' => 600]);

        expect($this->window->isOpen(vancouver('2026-11-18 15:00:00')))->toBeFalse();
    });

    test('an open day with a missing time is closed', function () {
        StoreHour::where('day_of_week', 3)->update(['closes_at' => null]);

        expect($this->window->isOpen(vancouver('2026-11-18 15:00:00')))->toBeFalse();
    });
});

describe('orderingEndsAt', function () {
    test('is closing time minus the cutoff while open', function () {
        expect($this->window->orderingEndsAt(vancouver('2026-11-18 15:00:00'))?->toDateTimeString())
            ->toBe('2026-11-18 20:45:00');
    });

    test('is null when ordering is closed', function (string $now) {
        expect($this->window->orderingEndsAt(vancouver($now)))->toBeNull();
    })->with([
        'before opening' => ['2026-11-18 09:00:00'],
        'after the cutoff' => ['2026-11-18 20:45:00'],
        'closed day' => ['2026-11-16 15:00:00'],
    ]);
});

describe('nextOpening', function () {
    test('finds the start of the next window', function (string $now, string $expected) {
        $next = $this->window->nextOpening(vancouver($now));

        expect($next?->toDateTimeString())->toBe($expected)
            ->and($next?->getTimezone()->getName())->toBe('America/Vancouver');
    })->with([
        'later today' => ['2026-11-18 09:00:00', '2026-11-18 11:00:00'],
        'tomorrow, after closing' => ['2026-11-18 22:00:00', '2026-11-19 11:00:00'],
        'tomorrow, after the cutoff but before closing' => ['2026-11-18 20:50:00', '2026-11-19 11:00:00'],
        'Saturday night to Sunday noon' => ['2026-11-21 21:30:00', '2026-11-22 12:00:00'],
        'skips consecutive closed days (Sun night -> Wed)' => ['2026-11-15 21:00:00', '2026-11-18 11:00:00'],
        'from a closed day' => ['2026-11-16 12:00:00', '2026-11-18 11:00:00'],
    ]);

    test('returns $now when ordering is already open', function () {
        $now = vancouver('2026-11-18 15:00:00');

        expect($this->window->nextOpening($now)?->equalTo($now))->toBeTrue();
    });

    test('returns $now in the display timezone when given a UTC time', function () {
        $next = $this->window->nextOpening(vancouver('2026-11-18 15:00:00')->utc());

        expect($next?->toDateTimeString())->toBe('2026-11-18 15:00:00')
            ->and($next?->getTimezone()->getName())->toBe('America/Vancouver');
    });

    test('finds the same weekday next week when it is the only open day', function () {
        setStoreHours([0 => null, 1 => ['11:00:00', '21:00:00'], 2 => null, 3 => null, 4 => null, 5 => null, 6 => null]);

        // Monday Nov 16 after the cutoff: next opening is Monday Nov 23, exactly 7 days later.
        expect($this->window->nextOpening(vancouver('2026-11-16 21:00:00'))?->toDateTimeString())
            ->toBe('2026-11-23 11:00:00');
    });

    test('returns null when every day is closed', function () {
        setStoreHours(array_fill(0, 7, null));

        expect($this->window->nextOpening(vancouver('2026-11-18 09:00:00')))->toBeNull()
            ->and($this->window->isOpen(vancouver('2026-11-18 15:00:00')))->toBeFalse();
    });
});

// Sunday hours are 12:00-20:00 (ordering until 19:45), so both DST Sundays open at 12:00 local time.

describe('daylight saving time in Vancouver (offsets from the timezone database)', function () {
    // Mar 8, 2026: spring forward, PST (UTC-8) -> PDT (UTC-7). True in every tzdata version.
    // Nov 1, 2026: newer tzdata (2026.x) has BC on permanent daylight time, so there is no
    //              fall back; older tzdata still has PDT -> PST. Either way, the store must
    //              open at 12:00 Vancouver wall-clock time.

    test('isOpen follows Vancouver wall-clock time on DST dates', function (string $local, bool $open) {
        // Pass a UTC instant, as the app does.
        expect($this->window->isOpen(vancouver($local)->utc()))->toBe($open);
    })->with([
        'Mar 8: 11:59:59' => ['2026-03-08 11:59:59', false],
        'Mar 8: 12:00 opens' => ['2026-03-08 12:00:00', true],
        'Mar 8: 19:44:59' => ['2026-03-08 19:44:59', true],
        'Mar 8: 19:45 cutoff' => ['2026-03-08 19:45:00', false],
        'Nov 1: 11:59:59' => ['2026-11-01 11:59:59', false],
        'Nov 1: 12:00 opens' => ['2026-11-01 12:00:00', true],
        'Nov 1: 19:44:59' => ['2026-11-01 19:44:59', true],
        'Nov 1: 19:45 cutoff' => ['2026-11-01 19:45:00', false],
    ]);

    test('spring forward on Mar 8 moves the opening instant by one hour', function () {
        // The only hard-coded offsets in this file: Mar 8, 2026 is the same in every tzdata version.
        expect($this->window->isOpen(utc('2026-03-08 18:59:59')))->toBeFalse()   // 11:59:59 PDT
            ->and($this->window->isOpen(utc('2026-03-08 19:00:00')))->toBeTrue() // 12:00 PDT
            ->and($this->window->nextOpening(vancouver('2026-03-07 22:00:00'))?->utc()->toDateTimeString())
            ->toBe('2026-03-08 19:00:00'); // not 20:00, which yesterday's PST offset would give
    });

    test('nextOpening lands on Sunday 12:00 local across the DST dates', function (string $localNow, string $expectedLocal) {
        $next = $this->window->nextOpening(vancouver($localNow));

        expect($next?->format('l H:i'))->toBe('Sunday 12:00')
            ->and($next?->equalTo(vancouver($expectedLocal)))->toBeTrue();
    })->with([
        'Saturday night before Mar 8' => ['2026-03-07 22:00:00', '2026-03-08 12:00:00'],
        'early Mar 8, before the 02:00 jump' => ['2026-03-08 01:30:00', '2026-03-08 12:00:00'],
        'Saturday night before Nov 1' => ['2026-10-31 22:00:00', '2026-11-01 12:00:00'],
        'early Nov 1' => ['2026-11-01 01:30:00', '2026-11-01 12:00:00'],
    ]);
});

describe('daylight saving time mechanics (America/Los_Angeles)', function () {
    // Los Angeles has the same 2026 transition dates and still falls back on Nov 1, so it
    // exercises both directions with fixed instants. It also proves the service reads
    // app.display_timezone instead of hard-coding a zone.
    beforeEach(function () {
        config(['app.display_timezone' => 'America/Los_Angeles']);
    });

    test('isOpen uses the offset in effect that day', function (string $utcNow, bool $open) {
        expect($this->window->isOpen(utc($utcNow)))->toBe($open);
    })->with([
        'spring forward: 11:59:59 PDT' => ['2026-03-08 18:59:59', false],
        'spring forward: 12:00 PDT opens' => ['2026-03-08 19:00:00', true],
        'spring forward: 19:44:59 PDT' => ['2026-03-09 02:44:59', true],
        'spring forward: 19:45 PDT cutoff' => ['2026-03-09 02:45:00', false],
        'fall back: 11:59:59 PST' => ['2026-11-01 19:59:59', false],
        'fall back: 12:00 PST opens' => ['2026-11-01 20:00:00', true],
        'fall back: 19:44:59 PST' => ['2026-11-02 03:44:59', true],
        'fall back: 19:45 PST cutoff' => ['2026-11-02 03:45:00', false],
    ]);

    test('nextOpening lands on the right UTC instant', function (string $localNow, string $expectedUtc) {
        $next = $this->window->nextOpening(CarbonImmutable::parse($localNow, 'America/Los_Angeles'));

        expect($next?->format('l H:i'))->toBe('Sunday 12:00')
            ->and($next?->getTimezone()->getName())->toBe('America/Los_Angeles')
            ->and($next?->utc()->toDateTimeString())->toBe($expectedUtc);
    })->with([
        'Saturday night before spring forward' => ['2026-03-07 22:00:00', '2026-03-08 19:00:00'],
        'early Sunday, before the 02:00 jump' => ['2026-03-08 01:30:00', '2026-03-08 19:00:00'],
        'Saturday night before fall back' => ['2026-10-31 22:00:00', '2026-11-01 20:00:00'],
        'early Sunday, during the repeated hour' => ['2026-11-01 01:30:00', '2026-11-01 20:00:00'],
    ]);
});
