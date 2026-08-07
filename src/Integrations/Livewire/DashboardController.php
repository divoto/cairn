<?php

declare(strict_types=1);

namespace Divoto\Cairn\Integrations\Livewire;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;

/**
 * Serves the Livewire dashboard as a page.
 *
 * The component itself renders a single root element with no document around
 * it, because it is also meant to be dropped into a host application's own
 * layout. Something therefore has to supply the page shell when Cairn serves
 * the dashboard at its own path, and this is it: the packaged layout with the
 * component inside it.
 *
 * There is no query here and no markup beyond the shell. Every number on the
 * page comes from the component, which comes from the same widgets and the
 * same report builder the Blade dashboard uses.
 */
final readonly class DashboardController
{
    public function __construct(
        private ViewFactory $views,
        private Config $config,
    ) {}

    public function __invoke(): View
    {
        return $this->views->make('cairn::livewire.page', [
            'path' => $this->path(),
        ]);
    }

    private function path(): string
    {
        $path = $this->config->get('cairn.dashboard.path');

        return is_string($path) && $path !== '' ? $path : 'cairn';
    }
}
