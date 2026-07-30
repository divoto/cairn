<?php

declare(strict_types=1);

namespace Divoto\Cairn\Data;

use Carbon\CarbonImmutable;
use Divoto\Cairn\Enums\Metric;

/**
 * One row of a report: a set of dimension values and the metrics measured for
 * them.
 *
 * Every UI surface — Blade, Livewire, Inertia, the JSON API, the Pulse cards,
 * CSV export — consumes this. None of them run a query of their own.
 */
final readonly class ReportRow
{
    /**
     * @param  array<string, string|int|null>  $dimensions  Dimension value => the value for this row.
     * @param  array<string, float>  $metrics  Metric value => the measured number.
     * @param  array<string, float>|null  $previous  The same metrics for the comparison window, if one was requested.
     * @param  bool  $approximate  True when any metric here is an estimate rather than an exact count.
     * @param  CarbonImmutable|null  $bucket  The bucket start, for timeseries rows.
     */
    public function __construct(
        public array $dimensions = [],
        public array $metrics = [],
        public ?array $previous = null,
        public bool $approximate = false,
        public ?CarbonImmutable $bucket = null,
    ) {}

    /**
     * The value of a metric on this row, or null if it was not requested.
     */
    public function metric(Metric $metric): ?float
    {
        return $this->metrics[$metric->value] ?? null;
    }

    /**
     * The change in a metric against the comparison window, as a ratio.
     *
     * Returns null when no comparison was requested, when the metric is
     * absent, or when the previous value was zero. A zero baseline has no
     * meaningful percentage change — reporting "+100%" or "∞" would invent a
     * number the data does not contain, so callers are told to render "no
     * comparison" instead.
     */
    public function change(Metric $metric): ?float
    {
        $current = $this->metrics[$metric->value] ?? null;
        $previous = $this->previous[$metric->value] ?? null;

        if ($current === null || $previous === null || $previous === 0.0) {
            return null;
        }

        return ($current - $previous) / $previous;
    }

    /**
     * The value of a dimension on this row.
     */
    public function dimension(string $key): string|int|null
    {
        return $this->dimensions[$key] ?? null;
    }

    /**
     * A flat array suitable for JSON encoding or CSV export.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'bucket' => $this->bucket?->toIso8601String(),
            'dimensions' => $this->dimensions,
            'metrics' => $this->metrics,
            'previous' => $this->previous,
            'approximate' => $this->approximate,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
