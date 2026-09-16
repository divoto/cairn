<?php

declare(strict_types=1);

namespace Divoto\Cairn\Support;

use Divoto\Cairn\Contracts\UniqueCounter;

/**
 * A unique counter that can answer for many days and dimension values at once.
 *
 * The reporting layer never wants one count in isolation: a thirty-day chart
 * wants thirty, and a ranked table wants one per row per day. Asking for them
 * one at a time is how a dashboard that is fast on a quiet site becomes slow
 * on a busy one — the work per call is small, but the number of calls is the
 * product of two things that both grow.
 *
 * **This is not part of {@see UniqueCounter}, on purpose.** Adding a method
 * to a public contract breaks every implementation written outside the
 * package. Cairn's own drivers implement this alongside the contract; a
 * counter that does not is still read correctly, one day and one dimension
 * value at a time.
 *
 * Internal, like everything under Support, and may change in a minor release.
 */
interface CountsUniquesInBulk
{
    /**
     * The daily distinct-visitor counts for every day under every dimension value.
     *
     * Implementations must answer in a bounded number of round trips rather
     * than by looping over `count()`, and must return a figure for every
     * requested pair, using zero where nothing was counted, so callers never
     * have to distinguish "no visitors" from "not in the result".
     *
     * @param  list<string>  $days  `Y-m-d` dates in UTC.
     * @param  list<string>  $dimensions  Opaque keys, as passed to `add()`.
     * @return array<string, array<string, int>> Dimension key => day => count.
     */
    public function counts(array $days, array $dimensions): array;
}
