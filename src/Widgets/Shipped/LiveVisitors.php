<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets\Shipped;

use Divoto\Cairn\Data\ReportRow;
use Divoto\Cairn\Reporting\Report;
use Divoto\Cairn\Widgets\Filters;
use Divoto\Cairn\Widgets\Widget;
use Divoto\Cairn\Widgets\WidgetLayout;
use Divoto\Cairn\Widgets\WidgetSchema;
use Illuminate\Support\Collection;
use Throwable;

/**
 * How many visitors are on the site right now.
 *
 * The one genuinely realtime number Cairn reports, and the only widget that
 * does not read aggregates — presence is a five-minute sliding window that
 * expires on its own.
 */
final class LiveVisitors extends Widget
{
    public function key(): string
    {
        return 'live-visitors';
    }

    public function title(): string
    {
        return 'Right now';
    }

    public function query(Filters $filters): Report
    {
        return $this->report($filters);
    }

    public function schema(): WidgetSchema
    {
        return new WidgetSchema(
            layout: WidgetLayout::Stat,
            empty: 'Nobody is on the site at the moment.',
        );
    }

    /**
     * @return Collection<int, ReportRow>
     */
    public function rows(Filters $filters): Collection
    {
        try {
            return new Collection([
                new ReportRow(metrics: ['live' => (float) $this->cairn->live()]),
            ]);
        } catch (Throwable $e) {
            report($e);

            return new Collection([new ReportRow(metrics: ['live' => 0.0])]);
        }
    }
}
