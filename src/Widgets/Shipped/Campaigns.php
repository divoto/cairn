<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets\Shipped;

use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Widgets\DimensionWidget;

final class Campaigns extends DimensionWidget
{
    public function key(): string
    {
        return 'campaigns';
    }

    public function title(): string
    {
        return 'Campaigns';
    }

    protected function dimension(): Dimension
    {
        return Dimension::UtmCampaign;
    }
}
