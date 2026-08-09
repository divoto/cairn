<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets\Shipped;

use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Widgets\DimensionWidget;

final class Browsers extends DimensionWidget
{
    public function key(): string
    {
        return 'browsers';
    }

    public function title(): string
    {
        return 'Browsers';
    }

    protected function dimension(): Dimension
    {
        return Dimension::Browser;
    }
}
