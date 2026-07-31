<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets\Shipped;

use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Widgets\DimensionWidget;

final class OperatingSystems extends DimensionWidget
{
    public function key(): string
    {
        return 'operating-systems';
    }

    public function title(): string
    {
        return 'Operating systems';
    }

    protected function dimension(): Dimension
    {
        return Dimension::OperatingSystem;
    }
}
