<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets\Shipped;

use Divoto\Cairn\Cairn;
use Divoto\Cairn\Contracts\Presence;
use Divoto\Cairn\Data\ReportRow;
use Divoto\Cairn\Reporting\Report;
use Divoto\Cairn\Widgets\Filters;
use Divoto\Cairn\Widgets\Widget;
use Divoto\Cairn\Widgets\WidgetLayout;
use Divoto\Cairn\Widgets\WidgetSchema;
use Illuminate\Support\Collection;
use Throwable;

/**
 * What people are looking at right now.
 *
 * Deliberately thin: a rotating hash and a page, and nothing else. A live
 * feed is a small dataset, and a small dataset with a rich record per row is
 * where individuals stop being anonymous.
 */
final class ActivityFeed extends Widget
{
    public function __construct(
        Cairn $cairn,
        private readonly Presence $presence,
    ) {
        parent::__construct($cairn);
    }

    public function key(): string
    {
        return 'activity-feed';
    }

    public function title(): string
    {
        return 'Active pages';
    }

    public function description(): string
    {
        return 'A rotating hash and a page. Nothing that identifies anyone.';
    }

    public function query(Filters $filters): Report
    {
        return $this->report($filters);
    }

    public function schema(): WidgetSchema
    {
        return new WidgetSchema(
            layout: WidgetLayout::Feed,
            empty: 'No activity in the last five minutes.',
            limit: 15,
        );
    }

    /**
     * @return Collection<int, ReportRow>
     */
    public function rows(Filters $filters): Collection
    {
        try {
            /** @var Collection<int, ReportRow> */
            return $this->presence->recent($this->schema()->limit)
                ->map(static fn (array $row): ReportRow => new ReportRow(
                    dimensions: [
                        // Shortened for display: the full hash is meaningless
                        // to a reader and rotates daily anyway.
                        'visitor' => substr($row['visitor'], 0, 8),
                        'page' => $row['page'] ?? '—',
                        'seen' => $row['last_seen_at'],
                    ],
                ))
                ->values();
        } catch (Throwable $e) {
            report($e);

            return new Collection;
        }
    }
}
