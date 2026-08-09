<?php

declare(strict_types=1);

namespace Divoto\Cairn\Support;

use Carbon\CarbonImmutable;
use Divoto\Cairn\Enums\Period;

/**
 * Calendar-aligned time buckets.
 *
 * All bucket arithmetic lives here and is done in Carbon, never in SQL. Every
 * supported engine spells date truncation differently, and each gets
 * daylight-saving boundaries wrong in its own way — so the calendar logic
 * stays in one testable place and SQL only ever sees two timestamps.
 *
 * Buckets are **aligned, not trimmed**. A day bucket starts at midnight and a
 * month bucket on the first, so "the last 30 days" is 30 whole buckets rather
 * than a rolling window sliced at the current minute. Two reports run an hour
 * apart over the same range therefore return the same numbers.
 *
 * Everything is computed in UTC, matching the salt rotation.
 */
final class Buckets
{
    /**
     * Snap an instant down to the start of the bucket containing it.
     */
    public static function align(CarbonImmutable $at, Period $period): CarbonImmutable
    {
        $at = $at->setTimezone('UTC');

        return match ($period) {
            Period::Hour => $at->startOfHour(),
            Period::Day => $at->startOfDay(),
            Period::Month => $at->startOfMonth(),
        };
    }

    /**
     * The start of the bucket after the one containing this instant.
     *
     * Uses calendar arithmetic rather than adding a fixed number of seconds:
     * days vary across daylight-saving transitions and months vary by
     * definition, so multiplication would drift.
     *
     * The instant is aligned before stepping, which matters more than it
     * looks: `2026-01-31` plus one month overflows to March, so a caller
     * passing an unaligned date would skip February entirely. Aligning first
     * makes the step total — every input lands on the next real bucket.
     */
    public static function next(CarbonImmutable $bucket, Period $period): CarbonImmutable
    {
        $aligned = self::align($bucket, $period);

        return match ($period) {
            Period::Hour => $aligned->addHour(),
            Period::Day => $aligned->addDay(),
            Period::Month => $aligned->addMonth(),
        };
    }

    /**
     * Every bucket from the one containing `$from` to the one containing
     * `$to`, inclusive at both ends.
     *
     * Inclusive because that is what a caller means: `--from=2026-03-01
     * --to=2026-03-31` is a request to rebuild March, and an exclusive end
     * would silently drop the 31st. It also means a window inside a single
     * bucket still rebuilds that bucket, rather than doing nothing — rolling
     * up "today" before midnight must not write nothing.
     *
     * @return list<CarbonImmutable>
     */
    public static function between(CarbonImmutable $from, CarbonImmutable $to, Period $period): array
    {
        $cursor = self::align($from, $period);
        $last = self::align($to, $period);

        $buckets = [];

        while ($cursor->lessThanOrEqualTo($last)) {
            $buckets[] = $cursor;
            $cursor = self::next($cursor, $period);
        }

        return $buckets;
    }

    /**
     * The bucket an instant belongs to, as a Unix timestamp.
     *
     * This is what lands in `cairn_aggregates.bucket`.
     */
    public static function stamp(CarbonImmutable $at, Period $period): int
    {
        return self::align($at, $period)->getTimestamp();
    }
}
