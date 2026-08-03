<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets\Shipped;

use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Widgets\DimensionWidget;

final class Devices extends DimensionWidget
{
    public function key(): string
    {
        return 'devices';
    }

    public function title(): string
    {
        return 'Devices';
    }

    protected function dimension(): Dimension
    {
        return Dimension::DeviceType;
    }
}
