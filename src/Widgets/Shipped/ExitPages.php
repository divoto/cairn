<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets\Shipped;

use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Widgets\DimensionWidget;

/**
 * Where visits ended.
 *
 * The counterpart to landing pages, and read more carefully: a visit has to end
 * somewhere, so a page at the top of this table is not automatically a problem.
 * The checkout confirmation page *should* be the last one somebody sees. It is
 * worth attention when a page appears here that nobody meant to be an ending.
 */
final class ExitPages extends DimensionWidget
{
    public function key(): string
    {
        return 'exit-pages';
    }

    public function title(): string
    {
        return 'Exit pages';
    }

    public function description(): string
    {
        return 'Every visit ends somewhere; a page here is not a fault by itself.';
    }

    /**
     * @return list<Metric>
     */
    protected function defaultMetrics(): array
    {
        return [Metric::Sessions];
    }

    protected function dimension(): Dimension
    {
        return Dimension::ExitPage;
    }

    protected function wide(): bool
    {
        return true;
    }
}
