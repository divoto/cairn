<?php

declare(strict_types=1);

namespace Divoto\Cairn\Http\Controllers;

use Divoto\Cairn\Widgets\Filters;
use Divoto\Cairn\Widgets\Shipped\Overview;
use Divoto\Cairn\Widgets\Widget;
use Divoto\Cairn\Widgets\WidgetRegistry;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Renders the Blade dashboard.
 *
 * There is no query in this class, and there will not be one. It reads the
 * filters out of the URL, asks the registry for widgets, and hands both to a
 * view — every number on the page comes from the report builder.
 */
final readonly class DashboardController
{
    public function __construct(
        private WidgetRegistry $registry,
        private ViewFactory $views,
        private Config $config,
    ) {}

    public function __invoke(Request $request): View
    {
        $filters = Filters::fromRequest($request);
        $widgets = $this->registry->all();

        $overview = null;

        foreach ($widgets as $widget) {
            if ($widget instanceof Overview) {
                $overview = $widget;
            }
        }

        return $this->views->make('cairn::dashboard', [
            'filters' => $filters,
            'widgets' => array_values(array_filter(
                $widgets,
                static fn (Widget $widget): bool => ! $widget instanceof Overview,
            )),
            'overview' => $overview,
            'series' => $overview?->series($filters),
            'path' => $this->path(),
        ]);
    }

    private function path(): string
    {
        $path = $this->config->get('cairn.dashboard.path');

        return is_string($path) && $path !== '' ? $path : 'cairn';
    }
}
