<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets\Shipped;

use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Widgets\DimensionWidget;

/**
 * Where visits began, and how many of them went no further.
 *
 * The first panel that can report a bounce rate per value. Everything else on
 * the dashboard is grouped by a column on an entry, and sessions are counted
 * from a table that carries no dimension columns — so "the bounce rate in
 * Singapore" is not a number that was ever measured. A landing page *is* a
 * column on the session table, so this one is.
 *
 * That makes it the SEO question people actually ask: not which pages get
 * traffic, which Top routes already answers, but which pages lose it.
 */
final class LandingPages extends DimensionWidget
{
    public function key(): string
    {
        return 'landing-pages';
    }

    public function title(): string
    {
        return 'Landing pages';
    }

    public function description(): string
    {
        return 'Bounded by what visitors requested, not by your route table.';
    }

    /**
     * @return list<Metric>
     */
    protected function defaultMetrics(): array
    {
        return [Metric::Sessions, Metric::BounceRate, Metric::AvgSessionDuration];
    }

    protected function dimension(): Dimension
    {
        return Dimension::EntryPage;
    }

    /**
     * A path is as long as a route name, and this table carries three columns
     * beside it.
     */
    protected function wide(): bool
    {
        return true;
    }
}
