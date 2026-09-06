<?php

declare(strict_types=1);

namespace Divoto\Cairn\Enums;

/**
 * How a metric's raw number should be presented.
 *
 * Formatting lives in the renderer, not here — this enum only says what kind
 * of quantity the number is, so that a Blade view, a JSON resource and a CSV
 * export can each make their own decision from the same fact.
 */
enum MetricUnit: string
{
    /** A whole count. Render with thousands separators, no decimals. */
    case Count = 'count';

    /** A fractional count, such as views per session. */
    case Decimal = 'decimal';

    /** A ratio in the range 0–1. Render as a percentage. */
    case Percentage = 'percentage';

    case Seconds = 'seconds';

    case Milliseconds = 'milliseconds';

    /**
     * A ratio stored multiplied by a thousand.
     *
     * Cumulative Layout Shift is the only one. It is a small fraction, and a
     * rollup that summed it as a float would drift; stored as thousandths it
     * sums as an integer and is divided back on the way out, so what a reader
     * sees is the 0.08 the web platform defines rather than the 80 the table
     * holds.
     */
    case Thousandths = 'thousandths';

    /**
     * A monetary amount.
     *
     * Cairn stores no currency code — a deployment reports in one currency,
     * and asking the package to guess would be worse than letting the deployer
     * label it.
     */
    case Currency = 'currency';
}
