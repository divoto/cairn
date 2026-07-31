<?php

declare(strict_types=1);

namespace Divoto\Cairn\Integrations\Inertia;

use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Widgets\Filters;
use Divoto\Cairn\Widgets\Widget;
use Divoto\Cairn\Widgets\WidgetRegistry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Inertia dashboard: typed page props, and nothing else.
 *
 * **Cairn ships controllers and props, not styled components.** The Vue and
 * React files under the `cairn-inertia` tag are publishable *starting points
 * that you own* — Cairn does not maintain them, will not restyle them to match
 * your application, and will not treat their appearance as a bug. Shipping
 * framework components that had to look right inside somebody else's design
 * system is a promise no package can keep.
 *
 * If you want a dashboard that works out of the box, use the Blade driver.
 */
final readonly class DashboardController
{
    public function __construct(
        private WidgetRegistry $registry,
    ) {}

    public function __invoke(Request $request): Response
    {
        $filters = Filters::fromRequest($request);

        return Inertia::render('Cairn/Dashboard', $this->props($filters));
    }

    /**
     * The page props, matching the published TypeScript definitions.
     *
     * @return array<string, mixed>
     */
    public function props(Filters $filters): array
    {
        [$from, $to] = $filters->window();

        return [
            'filters' => [
                'range' => $filters->range,
                'rangeLabel' => $filters->rangeLabel(),
                'comparison' => $filters->comparison->value,
                'interval' => $filters->interval()->value,
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'active' => $filters->active(),
            ],
            'ranges' => Filters::ranges(),
            'widgets' => array_map(
                fn (Widget $widget): array => [
                    'key' => $widget->key(),
                    'title' => $widget->title(),
                    'description' => $widget->description(),
                    'layout' => $widget->schema()->layout->value,
                    'dimension' => $widget->schema()->dimension,
                    'metrics' => array_map(
                        static fn (Metric $metric): array => [
                            'key' => $metric->value,
                            'label' => $metric->label(),
                            'unit' => $metric->unit()->value,
                        ],
                        $widget->schema()->metrics,
                    ),
                    'empty' => $widget->schema()->emptyMessage(),
                    'rows' => $widget->rows($filters)
                        ->map(static fn ($row): array => $row->toArray())
                        ->values()
                        ->all(),
                ],
                $this->registry->all(),
            ),
        ];
    }
}
