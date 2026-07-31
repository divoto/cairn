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
     * The start of the bucket after this one.
     *
     * Uses calendar arithmetic rather than adding a fixed number of seconds:
     * days vary across daylight-saving transitions and months vary by
     * definition, so multiplication would drift.
     */
    public static function next(CarbonImmutable $bucket, Period $period): CarbonImmutable
    {
        return match ($period) {
            Period::Hour => $bucket->addHour(),
            Period::Day => $bucket->addDay(),
            Period::Month => $bucket->addMonth(),
        };
    }

    /**
     * Every bucket start from `$from` up to but not including `$to`.
     *
     * Both ends are aligned first, so passing "now" and "an hour ago" still
     * produces whole buckets.
     *
     * @return list<CarbonImmutable>
     */
    public static function between(CarbonImmutable $from, CarbonImmutable $to, Period $period): array
    {
        $cursor = self::align($from, $period);
        $end = self::align($to, $period);

        // A window inside a single bucket still rolls that bucket up, rather
        // than doing nothing — otherwise rolling up "today" before midnight
        // would silently write nothing.
        if ($cursor->equalTo($end)) {
            return [$cursor];
        }

        $buckets = [];

        while ($cursor->lessThan($end)) {
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
