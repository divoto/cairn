<?php

declare(strict_types=1);

namespace Divoto\Cairn\Integrations;

use Divoto\Cairn\Integrations\Livewire\Dashboard as LivewireDashboard;
use Divoto\Cairn\Integrations\Pulse\LiveVisitors as PulseLiveVisitors;
use Divoto\Cairn\Integrations\Pulse\TopRoutes as PulseTopRoutes;
use Illuminate\Contracts\Config\Repository as Config;
use Inertia\Inertia;
use Laravel\Pulse\Pulse;
use Livewire\Livewire;
use Throwable;

/**
 * Registers whichever optional integrations are actually installed.
 *
 * All knowledge of Livewire, Inertia and Pulse lives inside this namespace and
 * nowhere else — an architecture test enforces it, which is why the service
 * provider calls this class rather than naming those packages itself.
 *
 * They are `suggest` entries and will never become `require` entries. An
 * analytics package that dragged a UI framework into every application that
 * installed it would deserve the complaints.
 */
final readonly class Integrations
{
    public function __construct(
        private Config $config,
    ) {}

    /**
     * Register everything whose package is present.
     *
     * Failures are contained: a broken integration must not stop Cairn
     * recording, which is the part that matters.
     */
    public function register(): void
    {
        if ($this->config->get('cairn.enabled') !== true) {
            return;
        }

        try {
            $this->registerLivewire();
            $this->registerPulse();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Whether Livewire is installed.
     */
    public function hasLivewire(): bool
    {
        return class_exists(Livewire::class);
    }

    /**
     * Whether Inertia is installed.
     */
    public function hasInertia(): bool
    {
        return class_exists(Inertia::class);
    }

    /**
     * Whether Pulse is installed.
     */
    public function hasPulse(): bool
    {
        return class_exists(Pulse::class);
    }

    /**
     * The Livewire dashboard, when Livewire is present and selected.
     */
    private function registerLivewire(): void
    {
        if (! $this->hasLivewire() || $this->config->get('cairn.dashboard.driver') !== 'livewire') {
            return;
        }

        Livewire::component('cairn-dashboard', LivewireDashboard::class);
    }

    /**
     * The two Pulse cards, when Pulse is present and enabled.
     */
    private function registerPulse(): void
    {
        if (! $this->hasPulse() || $this->config->get('cairn.pulse.enabled') !== true) {
            return;
        }

        // Pulse cards are Livewire components, and Pulse itself requires
        // Livewire, so this is safe inside the guard above.
        Livewire::component('cairn.live-visitors', PulseLiveVisitors::class);
        Livewire::component('cairn.top-routes', PulseTopRoutes::class);
    }
}
