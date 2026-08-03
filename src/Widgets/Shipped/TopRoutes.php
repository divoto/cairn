<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets\Shipped;

use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Widgets\DimensionWidget;

final class TopRoutes extends DimensionWidget
{
    public function key(): string
    {
        return 'top-routes';
    }

    public function title(): string
    {
        return 'Top routes';
    }

    public function description(): string
    {
        return 'Grouped by route name, so /orders/8814/invoice and /orders/9921/invoice count as one page rather than two.';
    }

    protected function dimension(): Dimension
    {
        return Dimension::Route;
    }
}
