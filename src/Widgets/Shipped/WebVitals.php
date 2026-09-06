<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets\Shipped;

use Divoto\Cairn\Data\ReportRow;
use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Widgets\DimensionWidget;
use Divoto\Cairn\Widgets\Filters;
use Divoto\Cairn\Widgets\WidgetLayout;
use Divoto\Cairn\Widgets\WidgetSchema;
use Illuminate\Support\Collection;

/**
 * Core Web Vitals per route.
 *
 * Grouped by route because that is the unit a developer can act on: "the site
 * is slow" is not a bug report, and "product.show has a 41% good LCP" is.
 *
 * The good share leads and the average follows. An average hides its own tail
 * — one slow render in ten barely moves a mean, and is exactly the experience
 * worth knowing about — so the headline is the share of page views that met
 * Google's threshold, with the average beside it for scale.
 *
 * A table rather than a chart. Six numbers across a dozen routes is a thing to
 * scan and sort, and no chart shape makes that easier to read.
 */
final class WebVitals extends DimensionWidget
{
    public function key(): string
    {
        return 'web-vitals';
    }

    public function title(): string
    {
        return 'Core Web Vitals';
    }

    public function description(): string
    {
        return 'Good is LCP under 2.5s, INP under 200ms, CLS under 0.10.';
    }

    /**
     * Drop the routes nothing measured.
     *
     * The vitals observers are Chromium-only and the beacon is optional, so on
     * most installations some routes have samples and some have none. A route
     * with no samples renders as a row of em dashes, which takes up as much
     * space as a real answer while saying nothing.
     *
     * @return Collection<int, ReportRow>
     */
    public function rows(Filters $filters): Collection
    {
        return parent::rows($filters)
            ->filter(static fn (ReportRow $row): bool => $row->metric(Metric::LcpGoodRate) !== null)
            ->values();
    }

    public function schema(): WidgetSchema
    {
        return new WidgetSchema(
            layout: WidgetLayout::Table,
            dimension: Dimension::Route->value,
            metrics: $this->defaultMetrics(),
            filterAs: Dimension::Route->value,
            // Nothing measures a vital without the beacon, so an empty panel
            // here means "not measured" rather than "all your pages are slow".
            requiresBeacon: true,
            wide: true,
        );
    }

    /**
     * Pageviews first, and not only to order the table by it.
     *
     * A good share with no denominator in sight invites reading 100% off three
     * samples as though it were three thousand.
     *
     * @return list<Metric>
     */
    protected function defaultMetrics(): array
    {
        return [
            Metric::Pageviews,
            Metric::LcpGoodRate,
            Metric::InpGoodRate,
            Metric::ClsGoodRate,
            Metric::AvgLcp,
            Metric::AvgInp,
            Metric::AvgCls,
        ];
    }

    protected function dimension(): Dimension
    {
        return Dimension::Route;
    }
}
