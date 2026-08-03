<?php

declare(strict_types=1);

namespace Divoto\Cairn\Reporting;

use BackedEnum;
use Carbon\CarbonImmutable;
use Divoto\Cairn\Contracts\Presence;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Contracts\TenantResolver;
use Divoto\Cairn\Contracts\UniqueCounter;
use Divoto\Cairn\Data\AggregateQuery;
use Divoto\Cairn\Data\ReportRow;
use Divoto\Cairn\Enums\Comparison;
use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Enums\Period;
use Divoto\Cairn\Exceptions\UnavailableDimensionException;
use Divoto\Cairn\Support\Buckets;
use Illuminate\Support\Collection;

/**
 * The one query layer.
 *
 * Blade, Livewire, Inertia, the JSON API, the Pulse cards and CSV export all
 * come through here. None of them writes a query of its own — which is what
 * keeps the numbers on the dashboard and the numbers in the API identical by
 * construction rather than by review.
 *
 * Three rules hold everything together:
 *
 * 1. **Only `cairn_aggregates` is read.** A request for something that was
 *    never rolled up throws, naming the combination. Silently scanning raw
 *    entries would turn a fast dashboard into a table scan nobody notices
 *    until the table is large.
 * 2. **Derived metrics are recomputed from their components at the level they
 *    are displayed at.** A week's bounce rate is that week's bounces over that
 *    week's sessions — never the mean of seven daily rates, which is a
 *    different and wrong number.
 * 3. **Tenancy is applied here, not by callers.** Isolation that depends on
 *    every call site remembering is not isolation.
 */
final class Report
{
    private CarbonImmutable $from;

    private CarbonImmutable $to;

    /** @var list<Metric> */
    private array $metrics = [Metric::Pageviews];

    /** @var list<Dimension> */
    private array $groupBy = [];

    /** @var array<string, list<string>> */
    private array $filters = [];

    private Comparison $comparison = Comparison::None;

    private Period $interval = Period::Day;

    private ?Metric $orderBy = null;

    private bool $descending = true;

    private ?int $limit = null;

    public function __construct(
        private readonly Storage $storage,
        private readonly UniqueCounter $uniques,
        private readonly Presence $presence,
        private readonly TenantResolver $tenants,
    ) {
        $this->to = CarbonImmutable::now('UTC')->endOfDay();
        $this->from = $this->to->subDays(29)->startOfDay();
    }

    /**
     * Bound the report. Both ends are inclusive.
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to): self
    {
        $this->from = $from->setTimezone('UTC');
        $this->to = $to->setTimezone('UTC');

        return $this;
    }

    /**
     * Shorthand for the last N whole days, ending today.
     */
    public function lastDays(int $days): self
    {
        $to = CarbonImmutable::now('UTC')->endOfDay();

        return $this->between($to->subDays(max(1, $days) - 1)->startOfDay(), $to);
    }

    public function metrics(Metric ...$metrics): self
    {
        $this->metrics = array_values($metrics);

        return $this;
    }

    public function groupBy(Dimension ...$dimensions): self
    {
        $this->groupBy = array_values($dimensions);

        return $this;
    }

    /**
     * Restrict the report to rows matching a dimension value.
     */
    public function filter(Dimension $dimension, string|int|BackedEnum $value): self
    {
        $resolved = $value instanceof BackedEnum ? (string) $value->value : (string) $value;

        $this->filters[$dimension->value] ??= [];
        $this->filters[$dimension->value][] = $resolved;

        return $this;
    }

    public function compare(Comparison $comparison): self
    {
        $this->comparison = $comparison;

        return $this;
    }

    public function interval(Period $period): self
    {
        $this->interval = $period;

        return $this;
    }

    public function orderByDesc(Metric $metric): self
    {
        $this->orderBy = $metric;
        $this->descending = true;

        return $this;
    }

    public function orderBy(Metric $metric): self
    {
        $this->orderBy = $metric;
        $this->descending = false;

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(1, $limit);

        return $this;
    }

    /**
     * The report, one row per dimension value.
     *
     * With no grouping this is a single row of site-wide totals.
     *
     * @return Collection<int, ReportRow>
     */
    public function get(): Collection
    {
        $this->guard();

        $rows = $this->rowsFor($this->from, $this->to);

        if ($this->comparison !== Comparison::None) {
            $rows = $this->attachComparison($rows);
        }

        $rows = $this->sort($rows);

        if ($this->limit !== null) {
            $rows = $rows->take($this->limit);
        }

        /** @var Collection<int, ReportRow> */
        return $rows->values();
    }

    /**
     * A single row of totals for the whole window.
     */
    public function total(): ReportRow
    {
        $first = (clone $this)->groupBy()->get()->first();

        return $first ?? new ReportRow;
    }

    /**
     * One row per bucket, for charting.
     *
     * Buckets with no data are returned as zeroes rather than omitted, so a
     * chart has a point for every day and a quiet Sunday is visible as a
     * trough rather than as a gap the eye interpolates across.
     *
     * @return Collection<int, ReportRow>
     */
    public function timeseries(): Collection
    {
        $this->guard();

        $stored = $this->storedMetrics();
        $tenant = $this->tenants->resolve();

        $rows = [];

        foreach (Buckets::between($this->from, $this->to, $this->interval) as $bucket) {
            $end = Buckets::next($bucket, $this->interval);

            $measured = $this->storage->aggregate(new AggregateQuery(
                from: $bucket,
                to: $end,
                period: $this->interval,
                metrics: $stored,
                groupBy: $this->groupBy,
                filters: $this->filters,
                tenantId: $tenant,
            ));

            $totals = $this->sumMetrics($measured);
            $totals = $this->withVisitors($totals, $bucket, $end);

            $rows[] = new ReportRow(
                dimensions: [],
                metrics: $this->derive($totals),
                approximate: $this->isApproximate($bucket, $end),
                bucket: $bucket,
            );
        }

        return new Collection($rows);
    }

    /**
     * How many visitors are on the site right now.
     *
     * The one genuinely realtime number Cairn reports, and the only one that
     * does not come from aggregates.
     */
    public function realtime(): int
    {
        return $this->presence->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_values(
            $this->get()->map(static fn (ReportRow $row): array => $row->toArray())->all()
        );
    }

    /**
     * The report as CSV, including a header row.
     */
    public function toCsv(): string
    {
        $rows = $this->get();

        if ($rows->isEmpty()) {
            return '';
        }

        $dimensions = array_keys($rows->first()->dimensions);
        $metrics = array_map(static fn (Metric $m): string => $m->value, $this->metrics);

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, [...$dimensions, ...$metrics], escape: '\\');

        foreach ($rows as $row) {
            $line = [];

            foreach ($dimensions as $dimension) {
                $line[] = $row->dimension($dimension);
            }

            foreach ($this->metrics as $metric) {
                $line[] = $row->metric($metric);
            }

            fputcsv($handle, $line, escape: '\\');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Refuse combinations that were never materialised.
     *
     * Done once, up front, so the error names what the caller asked for rather
     * than surfacing as an empty result they have to work backwards from.
     */
    private function guard(): void
    {
        foreach ($this->groupBy as $dimension) {
            if (! $dimension->isMaterialised()) {
                throw UnavailableDimensionException::notMaterialised($dimension);
            }
        }

        foreach (array_keys($this->filters) as $key) {
            $dimension = Dimension::from($key);

            if (! $dimension->isMaterialised()) {
                throw UnavailableDimensionException::notMaterialised($dimension);
            }

            // v1 rolls up one dimension at a time. Filtering on one while
            // grouping by another needs the pair, which is not stored.
            foreach ($this->groupBy as $grouped) {
                if ($grouped !== $dimension) {
                    throw UnavailableDimensionException::combination($grouped, $dimension);
                }
            }
        }

        if (in_array(Metric::Visitors, $this->metrics, true) && ! $this->visitorsAvailable()) {
            throw UnavailableDimensionException::metric(Metric::Visitors, $this->groupBy[0] ?? null);
        }
    }

    /**
     * Whether unique visitors are counted at the requested grouping.
     *
     * Counting happens as traffic arrives, against named dimensions, because a
     * set cardinality cannot be summed out of a rollup after the fact.
     */
    private function visitorsAvailable(): bool
    {
        if ($this->groupBy === []) {
            return true;
        }

        return count($this->groupBy) === 1 && $this->groupBy[0] === Dimension::Route;
    }

    /**
     * Build the rows for a window.
     *
     * @return Collection<int, ReportRow>
     */
    private function rowsFor(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $measured = $this->storage->aggregate(new AggregateQuery(
            from: Buckets::align($from, $this->interval),
            to: Buckets::next(Buckets::align($to, $this->interval), $this->interval),
            period: $this->interval,
            metrics: $this->storedMetrics(),
            groupBy: $this->groupBy,
            filters: $this->filters,
            tenantId: $this->tenants->resolve(),
        ));

        $measured = $this->applyFilters($measured);

        if ($this->groupBy === []) {
            $totals = $this->withVisitors($this->sumMetrics($measured), $from, $to);

            return new Collection([
                new ReportRow(
                    dimensions: [],
                    metrics: $this->derive($totals),
                    approximate: $this->isApproximate($from, $to),
                ),
            ]);
        }

        $dimension = $this->groupBy[0];

        /** @var Collection<int, ReportRow> */
        return $measured
            ->groupBy(fn (ReportRow $row): string => (string) ($row->dimension($dimension->value) ?? ''))
            ->map(function (Collection $group, string $value) use ($dimension, $from, $to): ReportRow {
                $totals = $this->sumMetrics($group);
                $totals = $this->withVisitors($totals, $from, $to, $dimension, $value);

                return new ReportRow(
                    dimensions: [$dimension->value => $value],
                    metrics: $this->derive($totals),
                    approximate: $this->isApproximate($from, $to),
                );
            })
            ->values();
    }

    /**
     * Drop rows that do not match the configured filters.
     *
     * Applied here rather than in SQL because a filter can only ever be on the
     * dimension being grouped by — {@see self::guard()} rejects anything else —
     * so the set being filtered is already small.
     *
     * @param  Collection<int, ReportRow>  $rows
     * @return Collection<int, ReportRow>
     */
    private function applyFilters(Collection $rows): Collection
    {
        if ($this->filters === []) {
            return $rows;
        }

        /** @var Collection<int, ReportRow> */
        return $rows->filter(function (ReportRow $row): bool {
            foreach ($this->filters as $key => $accepted) {
                $value = (string) ($row->dimension($key) ?? '');

                if (! in_array($value, $accepted, true)) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * Attach the comparison window's numbers to each row.
     *
     * @param  Collection<int, ReportRow>  $rows
     * @return Collection<int, ReportRow>
     */
    private function attachComparison(Collection $rows): Collection
    {
        $window = $this->comparison->windowFor($this->from, $this->to);

        if ($window === null) {
            return $rows;
        }

        $previous = $this->rowsFor($window[0], $window[1])
            ->keyBy(fn (ReportRow $row): string => $this->keyOf($row));

        /** @var Collection<int, ReportRow> */
        return $rows->map(function (ReportRow $row) use ($previous): ReportRow {
            $match = $previous->get($this->keyOf($row));

            return new ReportRow(
                dimensions: $row->dimensions,
                metrics: $row->metrics,
                // An absent comparison row means zero, not "unknown": the
                // dimension value genuinely had no traffic in that window.
                previous: $match instanceof ReportRow
                    ? $match->metrics
                    : array_fill_keys(array_keys($row->metrics), 0.0),
                approximate: $row->approximate,
                bucket: $row->bucket,
            );
        });
    }

    /**
     * Sum the stored metrics across every bucket in a set of rows.
     *
     * @param  Collection<int, ReportRow>  $rows
     * @return array<string, float>
     */
    private function sumMetrics(Collection $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            foreach ($row->metrics as $metric => $value) {
                $totals[$metric] = ($totals[$metric] ?? 0.0) + $value;
            }
        }

        return $totals;
    }

    /**
     * Add unique visitors, summed across the days in the window.
     *
     * Summing daily counts counts a returning visitor once per day they
     * visited. That is the direct consequence of rotating the salt every 24
     * hours — there is no cross-day identity to deduplicate against — and it
     * is why any row spanning more than a day is flagged approximate.
     *
     * @param  array<string, float>  $totals
     * @return array<string, float>
     */
    private function withVisitors(
        array $totals,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?Dimension $dimension = null,
        ?string $value = null,
    ): array {
        if (! in_array(Metric::Visitors, $this->metrics, true)) {
            return $totals;
        }

        $key = $dimension instanceof Dimension ? $dimension->value.':'.$value : 'overall';
        $visitors = 0;

        foreach (Buckets::between($from, $to, Period::Day) as $day) {
            $visitors += $this->uniques->count($day->format('Y-m-d'), $key);
        }

        $totals[Metric::Visitors->value] = (float) $visitors;

        return $totals;
    }

    /**
     * Recompute derived metrics from their stored components.
     *
     * A ratio with a zero denominator is omitted rather than reported as zero.
     * There is no bounce rate for zero sessions, and rendering 0% would be a
     * claim the data does not support.
     *
     * @param  array<string, float>  $totals
     * @return array<string, float>
     */
    private function derive(array $totals): array
    {
        $out = [];

        foreach ($this->metrics as $metric) {
            $ratio = $metric->ratio();

            if ($ratio === null) {
                $out[$metric->value] = $totals[$metric->value] ?? 0.0;

                continue;
            }

            $denominator = $totals[$ratio['denominator']->value] ?? 0.0;

            if ($denominator === 0.0) {
                continue;
            }

            $out[$metric->value] = ($totals[$ratio['numerator']->value] ?? 0.0) / $denominator;
        }

        return $out;
    }

    /**
     * The additive metrics that must be read from storage.
     *
     * A derived metric is replaced by its components; the ratio is recomputed
     * on the way out.
     *
     * @return list<Metric>
     */
    private function storedMetrics(): array
    {
        $needed = [];

        foreach ($this->metrics as $metric) {
            $ratio = $metric->ratio();

            if ($ratio === null) {
                if ($metric->isStored()) {
                    $needed[$metric->value] = $metric;
                }

                continue;
            }

            $needed[$ratio['numerator']->value] = $ratio['numerator'];
            $needed[$ratio['denominator']->value] = $ratio['denominator'];
        }

        return array_values($needed);
    }

    /**
     * Whether a row's numbers are estimates rather than exact counts.
     *
     * True whenever unique visitors are requested over more than one day,
     * because those are summed daily counts rather than a distinct count.
     */
    private function isApproximate(CarbonImmutable $from, CarbonImmutable $to): bool
    {
        if (! in_array(Metric::Visitors, $this->metrics, true)) {
            return false;
        }

        return count(Buckets::between($from, $to, Period::Day)) > 1;
    }

    /**
     * @param  Collection<int, ReportRow>  $rows
     * @return Collection<int, ReportRow>
     */
    private function sort(Collection $rows): Collection
    {
        if (! $this->orderBy instanceof Metric) {
            return $rows;
        }

        $metric = $this->orderBy;

        return $this->descending
            ? $rows->sortByDesc(static fn (ReportRow $row): float => $row->metric($metric) ?? 0.0)->values()
            : $rows->sortBy(static fn (ReportRow $row): float => $row->metric($metric) ?? 0.0)->values();
    }

    /**
     * A stable key for matching a row against its comparison row.
     */
    private function keyOf(ReportRow $row): string
    {
        return (string) json_encode($row->dimensions);
    }
}
