<?php

use App\Services\PickupSlots;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Reads store_hours through OrderingWindow, so it needs the app and a migrated database.
uses(TestCase::class, RefreshDatabase::class);

/*
 * Fixture: Friday Nov 13, 2026, open 11:00-22:00 with a 15-minute cutoff, so ordering
 * ends at 21:45 Vancouver time. Every other day is closed.
 *
 * Expected instants are built from Vancouver wall-clock times with the timezone database,
 * never from a hard-coded offset (BC is on permanent daylight time from tzdata 2026.x).
 */
beforeEach(function () {
    setStoreHours([5 => ['11:00:00', '22:00:00']]);

    $this->slots = app(PickupSlots::class);
});

function fridayAt(string $time): CarbonImmutable
{
    return CarbonImmutable::parse('2026-11-13 '.$time, 'America/Vancouver');
}

/**
 * @param  list<CarbonImmutable>  $slots
 * @return list<string>
 */
function wallClock(array $slots): array
{
    return array_map(fn (CarbonImmutable $slot): string => $slot->format('H:i'), $slots);
}

test('the worked example: 14:09:20 with a 6-minute drink', function () {
    $now = fridayAt('14:09:20');

    $estimate = $this->slots->asapEstimate($now, 6);
    $slots = $this->slots->slots($now, 6);

    expect($estimate->equalTo(fridayAt('14:15:20')))->toBeTrue()
        ->and($estimate->timezoneName)->toBe('America/Vancouver')
        ->and($slots)->toHaveCount(30)
        ->and($slots[0]->equalTo(fridayAt('14:30')))->toBeTrue()
        ->and(end($slots)->equalTo(fridayAt('21:45')))->toBeTrue()
        ->and(wallClock(array_slice($slots, 0, 3)))->toBe(['14:30', '14:45', '15:00']);
});

test('the clock can be passed in any timezone', function () {
    $slots = $this->slots->slots(fridayAt('14:09:20')->utc(), 6);

    expect($slots[0]->equalTo(fridayAt('14:30')))->toBeTrue()
        ->and($slots[0]->timezoneName)->toBe('America/Vancouver');
});

test('the latest slot can fall on the next UTC day', function () {
    $last = collect($this->slots->slots(fridayAt('14:09:20'), 6))->last();

    // Vancouver is 7 or 8 hours behind UTC, so 21:45 local is Saturday in UTC either way.
    expect($last->format('Y-m-d H:i'))->toBe('2026-11-13 21:45')
        ->and($last->utc()->toDateString())->toBe('2026-11-14');
});

test('a time already on a 15-minute mark is the earliest slot', function () {
    expect(wallClock($this->slots->slots(fridayAt('14:09:00'), 6))[0])->toBe('14:15');
});

test('one second past a mark rounds up to the next one', function () {
    expect(wallClock($this->slots->slots(fridayAt('14:09:01'), 6))[0])->toBe('14:30');
});

test('the longest prep time decides the earliest slot', function () {
    expect(wallClock($this->slots->slots(fridayAt('14:00:00'), 20))[0])->toBe('14:30');
});

test('the last slot can be exactly when ordering ends', function () {
    expect(wallClock($this->slots->slots(fridayAt('21:39:00'), 6)))->toBe(['21:45']);
});

test('there are no slots when none fits before ordering ends', function () {
    expect($this->slots->slots(fridayAt('21:39:01'), 6))->toBe([]);
});

test('the last slot is the last mark before ordering ends when the cutoff is not a multiple of 15', function () {
    setStoreHours([5 => ['11:00:00', '22:00:00']], cutoffMinutes: 20);

    expect(collect(wallClock($this->slots->slots(fridayAt('20:00:00'), 6)))->last())->toBe('21:30');
});

test('there are no slots while ordering is closed', function (string $time) {
    expect($this->slots->slots(fridayAt($time), 6))->toBe([]);
})->with(['before opening' => '10:59:59', 'after ordering ends' => '21:45:00', 'after closing' => '22:30:00']);

test('a closed day has no slots', function () {
    expect($this->slots->slots(CarbonImmutable::parse('2026-11-14 14:00', 'America/Vancouver'), 6))->toBe([]);
});

test('isAvailable accepts only exact slots', function () {
    $now = fridayAt('14:09:20');

    expect($this->slots->isAvailable(fridayAt('14:30'), $now, 6))->toBeTrue()
        ->and($this->slots->isAvailable(fridayAt('14:30')->utc(), $now, 6))->toBeTrue()
        ->and($this->slots->isAvailable(fridayAt('21:45'), $now, 6))->toBeTrue()
        ->and($this->slots->isAvailable(fridayAt('14:15'), $now, 6))->toBeFalse()
        ->and($this->slots->isAvailable(fridayAt('14:31'), $now, 6))->toBeFalse()
        ->and($this->slots->isAvailable(fridayAt('22:00'), $now, 6))->toBeFalse()
        ->and($this->slots->isAvailable(fridayAt('14:30')->addDay(), $now, 6))->toBeFalse();
});
