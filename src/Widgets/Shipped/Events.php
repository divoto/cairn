<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets\Shipped;

use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Widgets\DimensionWidget;

/**
 * Named events, as recorded by `Cairn::event()`.
 */
final class Events extends DimensionWidget
{
    public function key(): string
    {
        return 'events';
    }

    public function title(): string
    {
        return 'Events';
    }

    public function description(): string
    {
        return 'Recorded explicitly by your application, never inferred.';
    }

    protected function dimension(): Dimension
    {
        return Dimension::EventName;
    }

    /**
     * @return list<Metric>
     */
    protected function defaultMetrics(): array
    {
        return [Metric::Events];
    }
}
