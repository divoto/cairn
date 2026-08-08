<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets;

use Divoto\Cairn\Widgets\Shipped\ActivityFeed;
use Divoto\Cairn\Widgets\Shipped\Browsers;
use Divoto\Cairn\Widgets\Shipped\Campaigns;
use Divoto\Cairn\Widgets\Shipped\Channels;
use Divoto\Cairn\Widgets\Shipped\Conversions;
use Divoto\Cairn\Widgets\Shipped\Countries;
use Divoto\Cairn\Widgets\Shipped\Devices;
use Divoto\Cairn\Widgets\Shipped\Events;
use Divoto\Cairn\Widgets\Shipped\LiveVisitors;
use Divoto\Cairn\Widgets\Shipped\Mediums;
use Divoto\Cairn\Widgets\Shipped\OperatingSystems;
use Divoto\Cairn\Widgets\Shipped\Overview;
use Divoto\Cairn\Widgets\Shipped\Referrers;
use Divoto\Cairn\Widgets\Shipped\Sources;
use Divoto\Cairn\Widgets\Shipped\TopRoutes;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * The widgets the dashboard will draw, in order.
 *
 * Configured by class name so an application can reorder, remove, or add its
 * own without touching a template. A class that cannot be resolved is skipped
 * with a report rather than taking the dashboard down — a typo in config
 * should cost one panel, not the page.
 */
final readonly class WidgetRegistry
{
    /**
     * The widgets a fresh installation gets.
     *
     * Duplicated from config/cairn.php deliberately, as an upgrade safety net.
     * Laravel's mergeConfigFrom merges *top-level* keys only, so a deployer who
     * published config before this key existed would silently get no widgets
     * at all — a dashboard that renders successfully and shows nothing, with
     * no error to explain it. Falling back here means an upgrade adds the new
     * panels instead of removing every panel.
     *
     * An explicitly empty array is still honoured: that is somebody choosing to
     * show nothing, which is different from never having been asked.
     *
     * @var list<class-string<Widget>>
     */
    public const DEFAULTS = [
        Overview::class,
        LiveVisitors::class,
        Referrers::class,
        Channels::class,
        Countries::class,
        Devices::class,
        Browsers::class,
        OperatingSystems::class,
        Campaigns::class,
        Sources::class,
        Mediums::class,
        Events::class,
        Conversions::class,

        // The two full-width panels close the page, narrow panels above them.
        // A wide panel in the middle of the grid leaves a gap beside the panel
        // before it, because nothing narrow can be pulled up to fill the row.
        TopRoutes::class,
        ActivityFeed::class,
    ];

    public function __construct(
        private Container $container,
        private Config $config,
    ) {}

    /**
     * Every configured widget, resolved and available.
     *
     * @return list<Widget>
     */
    public function all(): array
    {
        $widgets = [];

        foreach ($this->configured() as $class) {
            $widget = $this->resolve($class);

            if ($widget instanceof Widget && $widget->isAvailable()) {
                $widgets[] = $widget;
            }
        }

        return $widgets;
    }

    /**
     * A single widget by its key, or null if it is not registered.
     */
    public function find(string $key): ?Widget
    {
        foreach ($this->all() as $widget) {
            if ($widget->key() === $key) {
                return $widget;
            }
        }

        return null;
    }

    /**
     * The widget class names from configuration.
     *
     * @return list<string>
     */
    private function configured(): array
    {
        $widgets = $this->config->get('cairn.dashboard.widgets');

        // Absent, rather than empty: see DEFAULTS.
        if ($widgets === null) {
            return self::DEFAULTS;
        }

        if (! is_array($widgets)) {
            return [];
        }

        return array_values(array_filter(
            $widgets,
            static fn (mixed $class): bool => is_string($class) && $class !== '',
        ));
    }

    private function resolve(string $class): ?Widget
    {
        try {
            $widget = $this->container->make($class);

            return $widget instanceof Widget ? $widget : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
