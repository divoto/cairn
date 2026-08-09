<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets\Shipped;

use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Widgets\DimensionWidget;

final class Mediums extends DimensionWidget
{
    public function key(): string
    {
        return 'mediums';
    }

    public function title(): string
    {
        return 'Campaign mediums';
    }

    protected function dimension(): Dimension
    {
        return Dimension::UtmMedium;
    }
}
