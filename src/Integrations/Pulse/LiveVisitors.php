<?php

declare(strict_types=1);

namespace Divoto\Cairn\Integrations\Pulse;

use Divoto\Cairn\Cairn;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

/**
 * A Pulse card showing how many visitors are on the site right now.
 *
 * Reads Cairn's own storage directly. Pulse cards may display data from
 * anywhere, so there is no reason to dual-write into `pulse_entries` — doing
 * so would duplicate every measurement and couple Cairn's retention to Pulse's
 * rolling window, which is the wrong model for analytics: Pulse is built to
 * forget, and a rollup is meant to be permanent.
 */
#[Lazy]
final class LiveVisitors extends Card
{
    public function render(): View
    {
        return app(ViewFactory::class)->make('cairn-pulse::live-visitors', [
            'visitors' => app(Cairn::class)->live(),
        ]);
    }
}
