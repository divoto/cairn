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
     * A monetary amount.
     *
     * Cairn stores no currency code — a deployment reports in one currency,
     * and asking the package to guess would be worse than letting the deployer
     * label it.
     */
    case Currency = 'currency';
}
