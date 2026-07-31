<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets\Shipped;

use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Widgets\DimensionWidget;

final class Channels extends DimensionWidget
{
    public function key(): string
    {
        return 'channels';
    }

    public function title(): string
    {
        return 'Channels';
    }

    public function description(): string
    {
        return 'How visits started, under a last-click model.';
    }

    protected function dimension(): Dimension
    {
        return Dimension::Channel;
    }
}
