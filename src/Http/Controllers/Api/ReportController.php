<?php

declare(strict_types=1);

namespace Divoto\Cairn\Http\Controllers\Api;

use Divoto\Cairn\Cairn;
use Divoto\Cairn\Data\ReportRow;
use Divoto\Cairn\Enums\Comparison;
use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Enums\Period;
use Divoto\Cairn\Exceptions\UnavailableDimensionException;
use Divoto\Cairn\Widgets\Filters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The JSON API.
 *
 * Backed by the same report builder as everything else, so it cannot disagree
 * with the dashboard. Disabled by default and behind `auth:sanctum` when
 * enabled — it can read everything the dashboard can, which is why it is off
 * until somebody decides who may call it.
 *
 * A request for a combination that was never materialised gets a 422 naming
 * the combination, rather than an empty result the caller has to work
 * backwards from.
 */
final readonly class ReportController
{
    public function __construct(
        private Cairn $cairn,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $filters = Filters::fromRequest($request);
        [$from, $to] = $filters->window();

        $report = $this->cairn->report()
            ->between($from, $to)
            ->interval($this->interval($request) ?? $filters->interval())
            ->compare($this->comparison($request) ?? $filters->comparison)
            ->metrics(...$this->metrics($request));

        $dimension = $this->dimension($request);

        if ($dimension instanceof Dimension) {
            $report = $report->groupBy($dimension);
        }

        $limit = $request->integer('limit');

        if ($limit > 0) {
            $report = $report->limit(min($limit, 1000));
        }

        try {
            $rows = $request->boolean('timeseries')
                ? $report->timeseries()
                : $report->get();
        } catch (UnavailableDimensionException $e) {
            return new JsonResponse([
                'errors' => [[
                    'status' => '422',
                    'title' => 'Unavailable combination',
                    'detail' => $e->getMessage(),
                ]],
            ], 422);
        }

        return new JsonResponse([
            'data' => $rows->map(static fn (ReportRow $row): array => [
                'type' => 'report-row',
                'attributes' => $row->toArray(),
            ])->values()->all(),
            'meta' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'interval' => $filters->interval()->value,
                // Surfaced rather than buried: a client charting these needs to
                // know when a figure is an estimate.
                'approximate' => $rows->contains(static fn (ReportRow $row): bool => $row->approximate),
            ],
        ]);
    }

    /**
     * The requested metrics, defaulting to pageviews.
     *
     * @return list<Metric>
     */
    private function metrics(Request $request): array
    {
        $requested = $request->query('metrics');
        $names = is_string($requested) ? explode(',', $requested) : [];

        $metrics = [];

        foreach ($names as $name) {
            $metric = Metric::tryFrom(trim($name));

            if ($metric instanceof Metric) {
                $metrics[] = $metric;
            }
        }

        return $metrics === [] ? [Metric::Pageviews] : $metrics;
    }

    private function dimension(Request $request): ?Dimension
    {
        $value = $request->query('group_by');

        return is_string($value) ? Dimension::tryFrom($value) : null;
    }

    private function interval(Request $request): ?Period
    {
        $value = $request->query('interval');

        return is_string($value) ? Period::tryFrom($value) : null;
    }

    private function comparison(Request $request): ?Comparison
    {
        $value = $request->query('compare');

        return is_string($value) ? Comparison::tryFrom($value) : null;
    }
}
