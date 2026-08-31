<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets\Shipped;

use Divoto\Cairn\Data\ReportRow;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Reporting\Report;
use Divoto\Cairn\Widgets\Filters;
use Divoto\Cairn\Widgets\Widget;
use Divoto\Cairn\Widgets\WidgetLayout;
use Divoto\Cairn\Widgets\WidgetSchema;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The headline numbers, with a chart and a comparison.
 *
 * The only widget that reads a timeseries rather than a ranked list, and the
 * only one that shows change against a previous window.
 */
final class Overview extends Widget
{
    public function key(): string
    {
        return 'overview';
    }

    public function title(): string
    {
        return 'Overview';
    }

    public function query(Filters $filters): Report
    {
        return $this->applyActiveFilter($this->report($filters), $filters)
            ->metrics(...$this->metrics());
    }

    public function schema(): WidgetSchema
    {
        return new WidgetSchema(
            layout: WidgetLayout::Overview,
            metrics: $this->metrics(),
        );
    }

    /**
     * The totals row, carrying its comparison.
     *
     * @return Collection<int, ReportRow>
     */
    public function rows(Filters $filters): Collection
    {
        try {
            return new Collection([$this->query($filters)->total()]);
        } catch (Throwable $e) {
            report($e);

            return new Collection([new ReportRow]);
        }
    }

    /**
     * The chart behind the headline numbers.
     *
     * @return Collection<int, ReportRow>
     */
    public function series(Filters $filters): Collection
    {
        try {
            return $this->applyActiveFilter($this->report($filters), $filters)
                ->metrics(Metric::Pageviews, Metric::Visitors)
                ->timeseries();
        } catch (Throwable $e) {
            report($e);

            return new Collection;
        }
    }

    /**
     * Four numbers, not fourteen.
     *
     * A headline row is read at a glance or not at all; a dashboard that opens
     * with a dozen figures is one nobody reads.
     *
     * @return list<Metric>
     */
    private function metrics(): array
    {
        return [
            Metric::Visitors,
            Metric::Pageviews,
            Metric::Sessions,
            Metric::BounceRate,
        ];
    }
}
