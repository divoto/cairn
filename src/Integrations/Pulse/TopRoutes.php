<?php

declare(strict_types=1);

namespace Divoto\Cairn\Integrations\Pulse;

use Carbon\CarbonImmutable;
use Divoto\Cairn\Cairn;
use Divoto\Cairn\Data\ReportRow;
use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\Metric;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;
use Throwable;

/**
 * A Pulse card showing the most-viewed routes in the last 24 hours.
 *
 * Goes through Cairn's report builder like every other surface, so it cannot
 * disagree with the dashboard. Reads Cairn's storage rather than writing into
 * `pulse_*` — see {@see LiveVisitors} for why.
 */
#[Lazy]
final class TopRoutes extends Card
{
    public function render(): View
    {
        return app(ViewFactory::class)->make('cairn::pulse.top-routes', [
            'routes' => $this->routes(),
        ]);
    }

    /**
     * @return Collection<int, ReportRow>
     */
    private function routes(): Collection
    {
        try {
            $now = CarbonImmutable::now('UTC');

            return app(Cairn::class)->report()
                ->between($now->subDay(), $now)
                ->metrics(Metric::Pageviews)
                ->groupBy(Dimension::Route)
                ->orderByDesc(Metric::Pageviews)
                ->limit(10)
                ->get();
        } catch (Throwable $e) {
            report($e);

            return new Collection;
        }
    }
}
