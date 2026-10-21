<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets\Shipped;

use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Widgets\DimensionWidget;

/**
 * Pages that answered with an error, and who sent people to them.
 *
 * Each row is a status, a URL and a referring host: one broken link, as
 * someone is hosting it. A 404 with a referrer is a dead link on another site
 * that a reader can go and get fixed. One without a referrer was typed,
 * bookmarked or probed. A 5xx is a bug in this application, and its row says
 * which page.
 *
 * Built from the status every pageview already carries, plus the requests that
 * matched no route at all. Those are not counted anywhere else on the
 * dashboard.
 */
final class BrokenPages extends DimensionWidget
{
    public function key(): string
    {
        return 'broken-pages';
    }

    public function title(): string
    {
        return 'Broken pages';
    }

    public function description(): string
    {
        return 'Requests answered with a 4xx or 5xx status, by page and by the site that linked to it.';
    }

    protected function dimension(): Dimension
    {
        return Dimension::ErrorPage;
    }

    /**
     * @return list<Metric>
     */
    protected function defaultMetrics(): array
    {
        return [Metric::Errors];
    }

    /**
     * A status, a path and a host do not fit in a third of a row.
     */
    protected function wide(): bool
    {
        return true;
    }
}
