<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets\Shipped;

use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Widgets\DimensionWidget;

final class Sources extends DimensionWidget
{
    public function key(): string
    {
        return 'sources';
    }

    public function title(): string
    {
        return 'Campaign sources';
    }

    protected function dimension(): Dimension
    {
        return Dimension::UtmSource;
    }
}
