<?php

declare(strict_types=1);

namespace Divoto\Cairn\Contracts;

use Carbon\CarbonInterface;
use Divoto\Cairn\Data\AggregateQuery;
use Divoto\Cairn\Data\Entry;
use Divoto\Cairn\Data\ReportRow;
use Divoto\Cairn\Enums\Period;
use Illuminate\Support\Collection;

/**
 * Persists entries and answers aggregate questions about them.
 *
 * A storage driver owns the raw table, the rollup table and the retention
 * mechanics for both. It does not own uniqueness counting or presence — those
 * have their own contracts because they have genuinely different storage
 * shapes and different correct implementations.
 */
interface Storage
{
    /**
     * Write entries to durable storage.
     *
     * @param  Collection<int, Entry>  $entries
     */
    public function store(Collection $entries): void;

    /**
     * Answer an aggregate query by reading rollups.
     *
     * Implementations must read the aggregate table only. Falling back to
     * scanning raw entries when a combination was not materialised is
     * forbidden: it turns a fast dashboard into a table scan silently, which
     * is worse than an error naming the missing combination.
     *
     * @return Collection<int, ReportRow>
     */
    public function aggregate(AggregateQuery $query): Collection;

    /**
     * Recompute rollups for a window, replacing whatever is there.
     *
     * Must be idempotent: running it twice over the same window leaves
     * identical rows. This is the authoritative repair path when
     * aggregate-on-write has drifted.
     *
     * @return int The number of aggregate rows written.
     */
    public function rollup(CarbonInterface $from, CarbonInterface $to, Period $period): int;

    /**
     * Delete raw data older than the given instant.
     *
     * Aggregates are not pruned here — they are the permanent record, and
     * removing them is governed by a separate retention setting that defaults
     * to keeping them forever.
     *
     * @return int The number of rows removed.
     */
    public function prune(CarbonInterface $before): int;
}
