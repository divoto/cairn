<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets;

use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Support\Beacon;

/**
 * How a widget wants to be drawn.
 *
 * A widget declares its shape; a renderer turns that shape plus data into
 * markup. Adding a widget therefore means adding one class rather than a class
 * and three templates, and the Blade, Livewire and Inertia adapters all render
 * from the same declaration instead of each carrying their own copy.
 */
final readonly class WidgetSchema
{
    /**
     * @param  WidgetLayout  $layout  How the renderer should present the data.
     * @param  string|null  $dimension  The dimension a table's rows are keyed by.
     * @param  list<Metric>  $metrics  The metrics to show, in display order.
     * @param  string|null  $filterAs  The query-string key clicking a row filters on.
     * @param  string|null  $empty  What to say when there is nothing to show.
     * @param  bool  $requiresBeacon  Whether this widget needs the optional JS beacon.
     * @param  bool  $wide  Whether the panel should span the full grid row.
     */
    public function __construct(
        public WidgetLayout $layout,
        public ?string $dimension = null,
        public array $metrics = [],
        public ?string $filterAs = null,
        public ?string $empty = null,
        public bool $requiresBeacon = false,
        public int $limit = 10,
        public bool $wide = false,
    ) {}

    /**
     * Whether the renderer should give this widget the whole row.
     *
     * A feed is always wide — its rows are a hash, a path and a time, which in
     * a third of a row wrap into something unreadable. Anything else asks.
     */
    public function isWide(): bool
    {
        return $this->wide || $this->layout === WidgetLayout::Feed;
    }

    /**
     * The message shown when a widget has no data.
     *
     * A widget that needs the beacon says so explicitly rather than showing a
     * zero — reporting "0 seconds average time on page" when nothing is
     * measuring it is worse than saying nothing. It also says which of two
     * different things an empty panel means: the beacon is off, or it is on
     * and no browser has reported in this period. Telling somebody who has
     * just placed the tag that it is not enabled sends them back to the
     * layout when the thing to check is the period, or the browser.
     */
    public function emptyMessage(): string
    {
        if ($this->empty !== null) {
            return $this->empty;
        }

        if (! $this->requiresBeacon) {
            return 'Nothing recorded in this period.';
        }

        return Beacon::enabled()
            ? 'The optional JavaScript beacon is enabled, but no browser has reported a measurement in this period.'
            : 'This needs the optional JavaScript beacon, which is not enabled.';
    }
}
