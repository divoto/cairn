<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets\Shipped;

use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Widgets\DimensionWidget;
use Divoto\Cairn\Widgets\WidgetLayout;
use Divoto\Cairn\Widgets\WidgetSchema;

/**
 * Where visitors are, at country level.
 *
 * Country only by default. Each step finer narrows the anonymity set of a
 * visitor hash — a country plus a browser plus a device class describes a very
 * large group, while a city plus the same attributes may describe a handful.
 */
final class Countries extends DimensionWidget
{
    public function key(): string
    {
        return 'countries';
    }

    public function title(): string
    {
        return 'Countries';
    }

    public function description(): string
    {
        return 'Resolved from a masked address that is never stored.';
    }

    public function schema(): WidgetSchema
    {
        return new WidgetSchema(
            layout: WidgetLayout::Map,
            dimension: Dimension::Country->value,
            metrics: $this->defaultMetrics(),
            filterAs: Dimension::Country->value,
            empty: 'No locations recorded. Cairn ships no geo database and will not '
                .'call a third party per request — install a local MaxMind database '
                .'and configure a resolver to enable this.',
        );
    }

    protected function dimension(): Dimension
    {
        return Dimension::Country;
    }
}
