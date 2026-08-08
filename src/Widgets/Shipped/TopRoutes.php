<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets\Shipped;

use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\RouteGrouping;
use Divoto\Cairn\Recorders\PageViews;
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

    /**
     * What the rows mean depends on how pageviews were grouped when they were
     * recorded, so the caption is read from the same setting rather than
     * asserting a grouping the deployment may not be using.
     */
    public function description(): string
    {
        return RouteGrouping::fromConfig(
            config('cairn.recorders.'.PageViews::class.'.group_by'),
        )->description();
    }

    protected function dimension(): Dimension
    {
        return Dimension::Route;
    }

    /**
     * Routes are long — a named route or a path is the widest value on the
     * dashboard, and in a third of a row it truncates on almost every entry.
     */
    protected function wide(): bool
    {
        return true;
    }
}
