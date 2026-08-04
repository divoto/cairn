<?php

declare(strict_types=1);

namespace Divoto\Cairn\Integrations\Livewire;

use Divoto\Cairn\Enums\Comparison;
use Divoto\Cairn\Widgets\Filters;
use Divoto\Cairn\Widgets\Shipped\Overview;
use Divoto\Cairn\Widgets\Widget;
use Divoto\Cairn\Widgets\WidgetRegistry;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The Livewire dashboard.
 *
 * **A wrapper, not a second dashboard.** It renders the same Blade partials as
 * the server-rendered version and asks the same widgets for their rows. There
 * is no query in this class and no duplicated markup — a Livewire dashboard
 * that drifted from the Blade one would mean two sets of numbers to keep
 * honest, and only one of them would ever get checked.
 *
 * What it adds is polling on the two widgets where a stale figure is actually
 * misleading: live visitors and the activity feed. Everything else on the page
 * is a rollup that changes at most once an hour, and re-fetching it every few
 * seconds would spend a database query to redraw an identical number.
 *
 * Filter state is bound to the query string with #[Url], so a Livewire view of
 * the dashboard is still a shareable link — the same property the Blade
 * version has.
 */
final class Dashboard extends Component
{
    #[Url(as: 'range', keep: true)]
    public string $range = '30d';

    #[Url(as: 'compare', keep: true)]
    public string $compare = 'previous_period';

    #[Url(as: 'route', except: '')]
    public string $route = '';

    #[Url(as: 'country', except: '')]
    public string $country = '';

    #[Url(as: 'channel', except: '')]
    public string $channel = '';

    /**
     * Apply a dimension filter, or clear it when the same value is chosen
     * again.
     */
    public function filterBy(string $dimension, string $value): void
    {
        if (! in_array($dimension, ['route', 'country', 'channel'], true)) {
            return;
        }

        $this->{$dimension} = $this->{$dimension} === $value ? '' : $value;
    }

    public function clearFilters(): void
    {
        $this->route = '';
        $this->country = '';
        $this->channel = '';
    }

    public function render(): View
    {
        $filters = $this->filters();
        $registry = app(WidgetRegistry::class);

        $widgets = $registry->all();
        $overview = null;

        foreach ($widgets as $widget) {
            if ($widget instanceof Overview) {
                $overview = $widget;
            }
        }

        // The component's own view, not 'cairn::dashboard': that one carries
        // the page layout, and Livewire allows a component exactly one root
        // element. Both render the same partial, so the two dashboards cannot
        // drift apart.
        return app(ViewFactory::class)->make('cairn::livewire.dashboard', [
            'filters' => $filters,
            'widgets' => array_values(array_filter(
                $widgets,
                static fn (Widget $widget): bool => ! $widget instanceof Overview,
            )),
            'overview' => $overview,
            'series' => $overview?->series($filters),
            'path' => $this->path(),
            // Tells the shared partials to add wire:poll to the two widgets
            // where a stale number would mislead.
            'live' => true,
        ]);
    }

    /**
     * The component's state, as the same Filters object the Blade dashboard
     * builds from the query string.
     */
    private function filters(): Filters
    {
        return new Filters(
            range: $this->range,
            comparison: Comparison::tryFrom($this->compare)
                ?? Comparison::PreviousPeriod,
            route: $this->route === '' ? null : $this->route,
            country: $this->country === '' ? null : $this->country,
            channel: $this->channel === '' ? null : $this->channel,
        );
    }

    private function path(): string
    {
        $path = config('cairn.dashboard.path');

        return is_string($path) && $path !== '' ? $path : 'cairn';
    }
}
