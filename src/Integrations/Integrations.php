<?php

declare(strict_types=1);

namespace Divoto\Cairn\Integrations;

use Divoto\Cairn\Http\Controllers\DashboardController as BladeDashboardController;
use Divoto\Cairn\Integrations\Inertia\DashboardController as InertiaDashboardController;
use Divoto\Cairn\Integrations\Livewire\Dashboard as LivewireDashboard;
use Divoto\Cairn\Integrations\Livewire\DashboardController as LivewireDashboardController;
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
    /**
     * Absolute path to the Pulse cards' Blade views.
     *
     * Deliberately outside `resources/views`, which is registered as the
     * `cairn::` namespace unconditionally. See {@see wantsPulse()}.
     *
     * `pulse-views` rather than `views-pulse` on purpose: `view:cache` drops
     * any view path that string-prefixes another, so a sibling named
     * `views-pulse` would be skipped as though it sat inside `views` — the
     * command would pass by accident rather than by design, and the accident
     * would end the moment that check learned about trailing slashes.
     */
    public const PULSE_VIEWS_PATH = __DIR__.'/../../resources/pulse-views';

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
     * The controller the dashboard route points at.
     *
     * `cairn.dashboard.driver` selects the dashboard, so it has to reach the
     * routing — the route file asks here rather than naming a controller,
     * which is what keeps every mention of an optional package inside this
     * namespace. The route named the Blade controller outright until this
     * existed, and the setting reached nothing: `livewire` and `inertia` both
     * served the Blade dashboard and returned 200, so a driver that was doing
     * nothing at all looked exactly like one that worked.
     *
     * An uninstalled package falls back to Blade rather than failing. The
     * driver names an optional dependency, and a dashboard that 500s because
     * a `suggest` entry is missing would be a worse answer than the canonical
     * dashboard.
     *
     * @return class-string
     */
    public function dashboardController(): string
    {
        $driver = $this->config->get('cairn.dashboard.driver');

        return match (true) {
            $driver === 'livewire' && $this->hasLivewire() => LivewireDashboardController::class,
            $driver === 'inertia' && $this->hasInertia() => InertiaDashboardController::class,
            default => BladeDashboardController::class,
        };
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
     * Whether the Pulse cards should exist at all.
     *
     * Public because the service provider registers their view namespace and
     * has to ask, and because the answer must be the same one
     * {@see registerPulse()} acts on: a namespace registered for cards that
     * are never registered is the bug this method exists to make impossible.
     * Asking through here rather than for `Laravel\Pulse` directly is what
     * keeps every mention of the package inside this namespace.
     *
     * `cairn.enabled` counts, and is checked here rather than only in
     * {@see register()}: a disabled Cairn must add nothing to the application
     * at all, view namespaces included.
     */
    public function wantsPulse(): bool
    {
        return $this->config->get('cairn.enabled') === true
            && $this->hasPulse()
            && $this->config->get('cairn.pulse.enabled') === true;
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
     *
     * Their views are the one part of Cairn that cannot be compiled without an
     * optional package installed — both use Pulse's own `<x-pulse::card>`
     * components — so they sit in a namespace the provider registers only when
     * {@see wantsPulse()} agrees. `view:cache` compiles every Blade file in
     * every registered view path with no regard for configuration or class
     * existence, so an unconditional namespace failed the command, and
     * therefore the deployment, of every application that had Cairn without
     * Pulse. Guarding the registration is what makes that impossible; guarding
     * the render alone was not enough, because nothing was rendering.
     */
    private function registerPulse(): void
    {
        if (! $this->wantsPulse()) {
            return;
        }

        // Pulse cards are Livewire components, and Pulse itself requires
        // Livewire, so this is safe inside the guard above.
        Livewire::component('cairn.live-visitors', PulseLiveVisitors::class);
        Livewire::component('cairn.top-routes', PulseTopRoutes::class);
    }
}
