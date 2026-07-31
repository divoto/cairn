<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Divoto\Cairn\Enums\Period;
use Divoto\Cairn\Support\Buckets;

/*
|--------------------------------------------------------------------------
| Buckets
|--------------------------------------------------------------------------
|
| All of Cairn's calendar arithmetic lives here rather than in SQL, because
| every engine spells date truncation differently and each gets daylight
| saving wrong in its own way. That makes this the one place the arithmetic
| can be wrong, so it is tested directly.
|
*/

function at(string $value): CarbonImmutable
{
    return CarbonImmutable::parse($value, 'UTC');
}

it('snaps an instant down to its bucket', function (): void {
    $instant = at('2026-03-14 13:45:59');

    expect(Buckets::align($instant, Period::Hour)->toDateTimeString())->toBe('2026-03-14 13:00:00')
        ->and(Buckets::align($instant, Period::Day)->toDateTimeString())->toBe('2026-03-14 00:00:00')
        ->and(Buckets::align($instant, Period::Month)->toDateTimeString())->toBe('2026-03-01 00:00:00');
});

it('converts to UTC before aligning', function (): void {
    // 00:30 in UTC+2 is still the previous UTC day.
    $instant = CarbonImmutable::parse('2026-03-15 00:30:00', '+02:00');

    expect(Buckets::align($instant, Period::Day)->toDateTimeString())->toBe('2026-03-14 00:00:00');
});

/**
 * Days vary across daylight-saving transitions and months vary by definition.
 * Adding a fixed number of seconds would drift; calendar arithmetic does not.
 */
it('steps to the next bucket by the calendar', function (): void {
    expect(Buckets::next(at('2026-03-14 13:00:00'), Period::Hour)->toDateTimeString())
        ->toBe('2026-03-14 14:00:00')
        ->and(Buckets::next(at('2026-03-14 00:00:00'), Period::Day)->toDateTimeString())
        ->toBe('2026-03-15 00:00:00')
        ->and(Buckets::next(at('2026-01-01 00:00:00'), Period::Month)->toDateTimeString())
        ->toBe('2026-02-01 00:00:00');
});

/**
 * `2026-01-31` plus one month overflows to March in Carbon, so an unaligned
 * input would skip February entirely. next() aligns before stepping.
 */
it('does not skip a month when given an unaligned date', function (): void {
    expect(Buckets::next(at('2026-01-31 18:00:00'), Period::Month)->toDateString())->toBe('2026-02-01')
        ->and(Buckets::next(at('2026-03-31 23:59:59'), Period::Month)->toDateString())->toBe('2026-04-01')
        ->and(Buckets::next(at('2026-03-14 13:45:00'), Period::Hour)->toDateTimeString())
        ->toBe('2026-03-14 14:00:00');
});

it('steps a month from January to February without landing on the 31st', function (): void {
    $february = Buckets::next(at('2026-01-01 00:00:00'), Period::Month);

    expect($february->toDateString())->toBe('2026-02-01')
        ->and(Buckets::next($february, Period::Month)->toDateString())->toBe('2026-03-01');
});

it('crosses a year boundary', function (): void {
    expect(Buckets::next(at('2026-12-01 00:00:00'), Period::Month)->toDateString())->toBe('2027-01-01')
        ->and(Buckets::next(at('2026-12-31 00:00:00'), Period::Day)->toDateString())->toBe('2027-01-01');
});

it('handles a leap day', function (): void {
    expect(Buckets::next(at('2028-02-28 00:00:00'), Period::Day)->toDateString())->toBe('2028-02-29')
        ->and(Buckets::next(at('2028-02-29 00:00:00'), Period::Day)->toDateString())->toBe('2028-03-01');
});

it('gives February its real length', function (): void {
    $days = Buckets::between(at('2028-02-01'), at('2028-02-29 23:59:59'), Period::Day);

    expect($days)->toHaveCount(29);

    $days = Buckets::between(at('2026-02-01'), at('2026-02-28 23:59:59'), Period::Day);

    expect($days)->toHaveCount(28);
});

/*
|--------------------------------------------------------------------------
| Windows
|--------------------------------------------------------------------------
|
| Inclusive at both ends. An exclusive end silently dropped the last day of
| every window, which is exactly the kind of off-by-one that produces a report
| that is quietly wrong rather than obviously broken.
|
*/

it('includes the bucket containing the end of the window', function (): void {
    $days = Buckets::between(at('2026-03-01'), at('2026-03-31 23:59:59'), Period::Day);

    expect($days)->toHaveCount(31)
        ->and($days[0]->toDateString())->toBe('2026-03-01')
        ->and($days[30]->toDateString())->toBe('2026-03-31');
});

it('rebuilds a single bucket when the window sits inside one', function (): void {
    $days = Buckets::between(at('2026-03-14 09:00:00'), at('2026-03-14 17:00:00'), Period::Day);

    expect($days)->toHaveCount(1)
        ->and($days[0]->toDateString())->toBe('2026-03-14');
});

it('produces whole buckets from partial bounds', function (): void {
    $hours = Buckets::between(at('2026-03-14 09:20:00'), at('2026-03-14 11:40:00'), Period::Hour);

    expect($hours)->toHaveCount(3)
        ->and($hours[0]->toDateTimeString())->toBe('2026-03-14 09:00:00')
        ->and($hours[2]->toDateTimeString())->toBe('2026-03-14 11:00:00');
});

it('returns nothing when the window runs backwards', function (): void {
    expect(Buckets::between(at('2026-03-15'), at('2026-03-14'), Period::Day))->toBe([]);
});

it('spans a year of months', function (): void {
    $months = Buckets::between(at('2026-01-01'), at('2026-12-31 23:59:59'), Period::Month);

    expect($months)->toHaveCount(12)
        ->and($months[11]->toDateString())->toBe('2026-12-01');
});

it('stamps a bucket as a Unix timestamp', function (): void {
    expect(Buckets::stamp(at('2026-03-14 13:45:00'), Period::Day))
        ->toBe(at('2026-03-14 00:00:00')->getTimestamp());
});

/**
 * Buckets must tile the timeline exactly: the end of one is the start of the
 * next, with no gap for an entry to fall into and no overlap to double-count
 * it.
 */
it('tiles the timeline without gaps or overlaps', function (Period $period, string $from, string $to): void {
    $buckets = Buckets::between(at($from), at($to), $period);
    $counter = count($buckets);

    for ($i = 1; $i < $counter; $i++) {
        expect(Buckets::next($buckets[$i - 1], $period)->toDateTimeString())
            ->toBe($buckets[$i]->toDateTimeString());
    }

    expect($buckets)->not->toBeEmpty();
})->with([
    'hours across a day boundary' => [Period::Hour, '2026-03-14 22:00:00', '2026-03-15 02:00:00'],
    'days across a month boundary' => [Period::Day, '2026-03-28', '2026-04-03'],
    'days across a DST transition' => [Period::Day, '2026-03-28', '2026-03-31'],
    'months across a year boundary' => [Period::Month, '2026-11-01', '2027-02-01'],
]);
