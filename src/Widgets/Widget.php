<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets;

use Divoto\Cairn\Cairn;
use Divoto\Cairn\Data\ReportRow;
use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Reporting\Report;
use Illuminate\Support\Collection;
use Throwable;

/**
 * One panel on the dashboard.
 *
 * **Designed for extension.** Applications add their own by subclassing this
 * and registering the subclass in `cairn.dashboard.widgets`, which is why it
 * is abstract rather than final.
 *
 * A widget declares two things: what to ask, and how the answer should look.
 * It does not render, and it does not query — `query()` describes a report and
 * the report builder executes it. That is what keeps the promise that no
 * query lives in a view, a controller or a component.
 */
abstract class Widget
{
    public function __construct(
        protected readonly Cairn $cairn,
    ) {}

    /**
     * A stable identifier, used in config and in the DOM.
     */
    abstract public function key(): string;

    /**
     * The heading shown above the panel.
     */
    abstract public function title(): string;

    /**
     * Describe the report this widget needs.
     *
     * Return a configured builder; the dashboard runs it. Widgets never touch
     * storage directly.
     */
    abstract public function query(Filters $filters): Report;

    /**
     * Declare how the widget should be drawn.
     */
    abstract public function schema(): WidgetSchema;

    /**
     * Run the widget's query and return its rows.
     *
     * Failures are contained to the panel. One widget asking for something
     * unavailable must not take the whole dashboard down with it — the other
     * thirteen are still worth showing.
     *
     * @return Collection<int, ReportRow>
     */
    public function rows(Filters $filters): Collection
    {
        try {
            return $this->query($filters)->get();
        } catch (Throwable $e) {
            report($e);

            return new Collection;
        }
    }

    /**
     * A one-line explanation shown under the title.
     *
     * Used to say plainly where a number is approximate or what it excludes,
     * rather than leaving the reader to guess.
     */
    public function description(): ?string
    {
        return null;
    }

    /**
     * Whether this widget should appear at all.
     *
     * A widget that cannot produce anything useful in the current
     * configuration hides rather than showing an empty panel forever.
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Start a report from the shared builder, pre-filtered.
     *
     * Every widget begins here, so a dimension filter selected in the UI
     * applies consistently across the whole dashboard rather than to whichever
     * panels remembered to honour it.
     */
    protected function report(Filters $filters): Report
    {
        [$from, $to] = $filters->window();

        // v1 materialises single-dimension rollups only, so a widget grouped
        // by one dimension cannot also be filtered by another. Each widget
        // applies whichever filter matches its own dimension and ignores the
        // rest; the report builder would throw otherwise.
        return $this->cairn->report()
            ->between($from, $to)
            ->interval($filters->interval())
            ->compare($filters->comparison);
    }

    /**
     * Apply the filter for this widget's own dimension, if one is set.
     */
    protected function applyOwnFilter(Report $report, Filters $filters, Dimension $dimension): Report
    {
        $value = $filters->active()[$dimension->value] ?? null;

        return $value === null ? $report : $report->filter($dimension, $value);
    }

    /**
     * Apply the active filter to a report that groups by nothing.
     *
     * An ungrouped report can be narrowed to any materialised dimension —
     * "how much traffic came from Singapore" reads the country rollup and
     * sums it — so a widget showing totals honours whichever filter is set
     * rather than only its own. Metrics that were never measured at that
     * grain are omitted from the result; see
     * {@see Metric::isMeasuredPerDimension()}.
     */
    protected function applyActiveFilter(Report $report, Filters $filters): Report
    {
        foreach ($filters->active() as $dimension => $value) {
            $enum = Dimension::tryFrom($dimension);

            if ($enum instanceof Dimension && $enum->isMaterialised()) {
                $report = $report->filter($enum, $value);
            }
        }

        return $report;
    }

    /**
     * The metrics a ranked table shows by default.
     *
     * @return list<Metric>
     */
    protected function defaultMetrics(): array
    {
        return [Metric::Pageviews];
    }
}
