<?php

declare(strict_types=1);

namespace Divoto\Cairn\Storage;

use Carbon\CarbonInterface;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Data\AggregateQuery;
use Divoto\Cairn\Data\Entry;
use Divoto\Cairn\Data\ReportRow;
use Divoto\Cairn\Enums\Period;
use Illuminate\Support\Collection;

/**
 * A storage driver that persists nothing and reports nothing.
 *
 * Bound when `cairn.enabled` is false. Reports run against it successfully and
 * return no rows, so a dashboard rendered on a disabled installation shows
 * empty states rather than errors.
 */
final class NullStorage implements Storage
{
    /**
     * @param  Collection<int, Entry>  $entries
     */
    public function store(Collection $entries): void
    {
        // Deliberately empty.
    }

    /**
     * @return Collection<int, ReportRow>
     */
    public function aggregate(AggregateQuery $query): Collection
    {
        return new Collection;
    }

    public function rollup(CarbonInterface $from, CarbonInterface $to, Period $period): int
    {
        return 0;
    }

    public function prune(CarbonInterface $before): int
    {
        return 0;
    }
}
