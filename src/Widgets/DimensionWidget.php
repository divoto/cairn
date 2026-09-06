<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets;

use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Reporting\Report;

/**
 * A ranked table of one dimension.
 *
 * Nine of the shipped widgets are exactly this with a different dimension, so
 * they are nine short subclasses rather than nine copies of the same query. A
 * widget that needed something genuinely different would extend
 * {@see Widget} directly.
 *
 * **Designed for extension** — this is the shortest path to a custom widget.
 */
abstract class DimensionWidget extends Widget
{
    /**
     * The dimension this table ranks.
     */
    abstract protected function dimension(): Dimension;

    public function key(): string
    {
        return $this->dimension()->value;
    }

    public function title(): string
    {
        return $this->dimension()->label();
    }

    public function query(Filters $filters): Report
    {
        $dimension = $this->dimension();

        return $this->applyOwnFilter($this->report($filters), $filters, $dimension)
            ->metrics(...$this->defaultMetrics())
            ->groupBy($dimension)
            ->orderByDesc($this->defaultMetrics()[0] ?? Metric::Pageviews)
            ->limit($this->schema()->limit);
    }

    public function schema(): WidgetSchema
    {
        return new WidgetSchema(
            layout: WidgetLayout::Table,
            dimension: $this->dimension()->value,
            metrics: $this->defaultMetrics(),
            filterAs: $this->filterable() ? $this->dimension()->value : null,
            requiresBeacon: $this->dimension()->requiresBeacon(),
            wide: $this->wide(),
        );
    }

    /**
     * Whether this table should span the full width of the grid.
     *
     * Off by default: the point of the grid is that most of these tables are
     * short lists of short values, and three narrow panels side by side read
     * faster than three wide ones stacked. A dimension whose values are long —
     * a route, a URL — is the exception.
     */
    protected function wide(): bool
    {
        return false;
    }

    /**
     * Whether clicking a row should filter the dashboard by it.
     *
     * Only the dimensions the filter bar understands are clickable.
     * Making every table clickable would produce URLs the rest of the
     * dashboard cannot honour, since v1 rolls up one dimension at a time.
     */
    protected function filterable(): bool
    {
        return in_array(
            $this->dimension(),
            [
                Dimension::Route,
                Dimension::Country,
                Dimension::Channel,
                Dimension::Language,
                Dimension::ScreenClass,
            ],
            true,
        );
    }
}
