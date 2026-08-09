<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets\Shipped;

use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Widgets\DimensionWidget;

/**
 * Named conversions and their value.
 *
 * Cairn does not guess what counts as a conversion — the application says so
 * by calling `Cairn::conversion()`.
 */
final class Conversions extends DimensionWidget
{
    public function key(): string
    {
        return 'conversions';
    }

    public function title(): string
    {
        return 'Conversions';
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
        return [Metric::Conversions, Metric::ConversionValue];
    }
}
