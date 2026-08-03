<?php

declare(strict_types=1);

namespace Divoto\Cairn\Support;

use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Enums\MetricUnit;

/**
 * Turns a metric's raw number into something readable.
 *
 * Kept out of the views so that Blade, the JSON API and a CSV export can each
 * make their own decision from the same fact, and so the rounding rules are in
 * one place rather than repeated in fourteen templates.
 */
final class Format
{
    /**
     * Format a metric value, or an em dash when there is nothing to show.
     *
     * A null is genuinely different from a zero here: a bounce rate over zero
     * sessions is unknown, not 0%, and printing "0%" would be a claim the data
     * does not support.
     */
    public static function metric(Metric $metric, ?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        return match ($metric->unit()) {
            MetricUnit::Percentage => number_format($value * 100, 1).'%',
            MetricUnit::Seconds => self::duration($value),
            MetricUnit::Milliseconds => self::milliseconds($value),
            MetricUnit::Currency => number_format($value, 2),
            MetricUnit::Decimal => number_format($value, 2),
            MetricUnit::Count => self::count($value),
        };
    }

    /**
     * A whole count, abbreviated once it stops fitting.
     *
     * A dashboard column is narrow, and "1.2M" is read faster than
     * "1,238,411" — which nobody reads to the last digit anyway.
     */
    public static function count(float $value): string
    {
        $absolute = abs($value);

        if ($absolute >= 1_000_000) {
            return self::trim($value / 1_000_000).'M';
        }

        if ($absolute >= 10_000) {
            return self::trim($value / 1_000).'k';
        }

        return number_format($value);
    }

    /**
     * A duration in seconds, as minutes and seconds once it is long enough.
     */
    public static function duration(float $seconds): string
    {
        $seconds = (int) round($seconds);

        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return $minutes.'m '.($seconds % 60).'s';
        }

        return intdiv($minutes, 60).'h '.($minutes % 60).'m';
    }

    /**
     * A server duration, in the unit that reads best at that size.
     */
    public static function milliseconds(float $milliseconds): string
    {
        return $milliseconds >= 1000
            ? self::trim($milliseconds / 1000).'s'
            : (int) round($milliseconds).'ms';
    }

    /**
     * One decimal place, with a trailing ".0" removed.
     */
    private static function trim(float $value): string
    {
        $formatted = number_format($value, 1);

        return str_ends_with($formatted, '.0') ? substr($formatted, 0, -2) : $formatted;
    }
}
