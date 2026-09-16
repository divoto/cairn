<?php

declare(strict_types=1);

namespace Divoto\Cairn\Contracts;

/**
 * Counts distinct visitors per day, per dimension value.
 *
 * Uniqueness cannot be summed out of a rollup table — the cardinality of a set
 * is not the sum of the cardinalities of its parts — so it gets its own
 * storage and its own contract.
 *
 * **Counting is scoped to a single day, deliberately.** The visitor salt
 * rotates every 24 hours, so the same person produces an unrelated hash
 * tomorrow. There is no cross-day set to deduplicate against, and therefore no
 * cross-range merge to implement: a monthly unique count is the *sum of daily
 * unique counts*.
 *
 * That inflates long-range visitor numbers relative to a cookie-based tool,
 * which would report one visitor where Cairn reports one per day they visited.
 * This is a deliberate consequence of not being able to follow people across
 * days, not a bug and not a rounding error. Report rows spanning more than a
 * day carry an `approximate` flag so a UI can say so.
 */
interface UniqueCounter
{
    /**
     * Record that a visitor was seen on a day, under a dimension value.
     *
     * Must be idempotent — the same visitor on the same day under the same
     * dimension counts once.
     *
     * @param  string  $day  A `Y-m-d` date in UTC.
     * @param  string  $dimension  An opaque key identifying what is being counted.
     * @param  string  $visitor  The raw 16-byte daily-rotating visitor hash.
     */
    public function add(string $day, string $dimension, string $visitor): void;

    /**
     * How many distinct visitors were seen on a day under a dimension value.
     *
     * @param  string  $day  A `Y-m-d` date in UTC.
     */
    public function count(string $day, string $dimension): int;

    /**
     * The same counts for many days and many dimension values at once.
     *
     * The reporting layer never wants one of these in isolation: a thirty-day
     * chart wants thirty, and a ranked table wants one per row per day. Asking
     * for them one at a time is how a dashboard that is fast on a quiet site
     * becomes slow on a busy one — the work per call is small, but the number
     * of calls is the product of two things that both grow.
     *
     * Implementations must answer in a bounded number of round trips rather
     * than by looping over {@see self::count()}, and must return a figure for
     * every requested pair, using zero where nothing was counted, so callers
     * never have to distinguish "no visitors" from "not in the result".
     *
     * @param  list<string>  $days  `Y-m-d` dates in UTC.
     * @param  list<string>  $dimensions  Opaque keys, as passed to {@see self::add()}.
     * @return array<string, array<string, int>> Dimension key => day => count.
     */
    public function counts(array $days, array $dimensions): array;

    /**
     * Drop counting data for days before the given one.
     *
     * @param  string  $beforeDay  A `Y-m-d` date in UTC; this day is kept.
     * @return int The number of records removed.
     */
    public function prune(string $beforeDay): int;
}
