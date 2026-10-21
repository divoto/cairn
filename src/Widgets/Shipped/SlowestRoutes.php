<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets\Shipped;

use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Reporting\Report;
use Divoto\Cairn\Widgets\DimensionWidget;
use Divoto\Cairn\Widgets\Filters;

/**
 * Routes ranked by how long the server took to answer them.
 *
 * Sits beside Core Web Vitals so that "the server is slow" and "the page is
 * slow" read as two separate answers. A route can render in 40ms and still
 * fail LCP on a heavy image, and a route can take two seconds to answer
 * however light its page is; one panel cannot tell those apart.
 *
 * Read from the response time every pageview already carries. Nothing new is
 * recorded for it.
 */
final class SlowestRoutes extends DimensionWidget
{
    /**
     * How many pageviews a route needs before it is ranked.
     *
     * An average over one or two requests is mostly noise, and noise at the
     * top of a ranked table is what a reader acts on first.
     */
    public const MINIMUM_PAGEVIEWS = 5;

    public function key(): string
    {
        return 'slowest-routes';
    }

    public function title(): string
    {
        return 'Slowest routes';
    }

    /**
     * The clock starts when the web middleware group starts, so the time the
     * framework spends booting before that is not in these numbers.
     */
    public function description(): string
    {
        return 'Average server response time, for routes with at least '
            .self::MINIMUM_PAGEVIEWS.' pageviews. Measured from the web middleware onwards.';
    }

    public function query(Filters $filters): Report
    {
        return $this->applyOwnFilter($this->report($filters), $filters, Dimension::Route)
            ->metrics(...$this->defaultMetrics())
            ->groupBy(Dimension::Route)
            ->minimum(Metric::Pageviews, self::MINIMUM_PAGEVIEWS)
            ->orderByDesc(Metric::AvgResponseTime)
            ->limit($this->schema()->limit);
    }

    protected function dimension(): Dimension
    {
        return Dimension::Route;
    }

    /**
     * The average leads, and pageviews sit beside it so a reader can see how
     * much traffic each average is built on.
     *
     * @return list<Metric>
     */
    protected function defaultMetrics(): array
    {
        return [Metric::AvgResponseTime, Metric::Pageviews];
    }

    /**
     * Route names are the longest values on the dashboard.
     */
    protected function wide(): bool
    {
        return true;
    }
}
