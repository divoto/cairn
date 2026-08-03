<?php

declare(strict_types=1);

namespace Divoto\Cairn\Enums;

use Carbon\CarbonImmutable;

/**
 * Which earlier window a report should be measured against.
 *
 * The comparison window is always the same *length* as the current one, so
 * "up 12%" means something. A seven-day window compares against the seven days
 * before it, not against last calendar week, because the two would differ
 * whenever the window does not start on a Monday.
 */
enum Comparison: string
{
    case None = 'none';

    /** The window of equal length immediately before this one. */
    case PreviousPeriod = 'previous_period';

    /**
     * The same window one year earlier.
     *
     * Useful for anything seasonal, where last month is the wrong baseline.
     */
    case PreviousYear = 'previous_year';

    /**
     * The window this comparison refers to, given the current one.
     *
     * @return array{CarbonImmutable, CarbonImmutable}|null
     */
    public function windowFor(CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        if ($this === self::None) {
            return null;
        }

        if ($this === self::PreviousYear) {
            return [$from->subYear(), $to->subYear()];
        }

        // Inclusive windows: a single day is from 00:00:00 to 23:59:59, which
        // is one second short of a day. Adding that second back keeps the
        // previous window the same length rather than one second shorter.
        $length = $to->getTimestamp() - $from->getTimestamp() + 1;

        return [
            $from->subSeconds($length),
            $to->subSeconds($length),
        ];
    }

    /**
     * A label for the comparison, for dashboards and exports.
     */
    public function label(): string
    {
        return match ($this) {
            self::None => 'No comparison',
            self::PreviousPeriod => 'Previous period',
            self::PreviousYear => 'Previous year',
        };
    }
}
