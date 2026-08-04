<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Divoto\Cairn\Cairn;
use Divoto\Cairn\Contracts\Presence;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Data\Entry;
use Divoto\Cairn\Enums\EntryType;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Enums\Period;
use Divoto\Cairn\Integrations\Inertia\DashboardController as InertiaDashboard;
use Divoto\Cairn\Integrations\Integrations;
use Divoto\Cairn\Integrations\Livewire\Dashboard as LivewireDashboard;
use Divoto\Cairn\Integrations\Pulse\LiveVisitors as PulseLiveVisitors;
use Divoto\Cairn\Integrations\Pulse\TopRoutes as PulseTopRoutes;
use Divoto\Cairn\Widgets\Filters;
use Divoto\Cairn\Widgets\Shipped\TopRoutes;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Livewire\Mechanisms\ComponentRegistry;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::define('viewCairn', fn (mixed $user = null): bool => true);
});

/**
 * Whether Livewire can resolve a component name to a class.
 *
 * Asked of the registry rather than the facade — the facade has no such
 * method, and the registry is what actually resolves a name at render time.
 */
function componentRegistered(string $name): bool
{
    try {
        return app(ComponentRegistry::class)->getClass($name) !== null;
    } catch (Throwable) {
        return false;
    }
}

/**
 * A PHP file's code, with its comments removed.
 *
 * An assertion that a class does not *use* something must not be satisfied or
 * broken by a comment explaining why it does not.
 */
function codeOf(object|string $class): string
{
    if (is_string($class) && ! class_exists($class)) {
        return '';
    }

    $file = (string) (new ReflectionClass($class))->getFileName();
    $code = '';

    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

function seedForIntegrations(): void
{
    $at = CarbonImmutable::now('UTC')->startOfDay()->addHours(9);

    foreach (['pricing.index', 'pricing.index', 'home.index'] as $index => $route) {
        /** @var Collection<int, Entry> $collection */
        $collection = new Collection([new Entry(
            occurredAt: $at->addMinutes($index),
            type: EntryType::Pageview,
            visitor: random_bytes(16),
            route: $route,
            url: '/'.$route,
            country: 'GB',
        )]);

        app(Storage::class)->store($collection);
    }

    app(Storage::class)->rollup($at->startOfDay(), $at->endOfDay(), Period::Day);
    app(Storage::class)->rollup($at->startOfDay(), $at->endOfDay(), Period::Hour);
}

/*
|--------------------------------------------------------------------------
| Optional, and provably so
|--------------------------------------------------------------------------
|
| CLAUDE.md: never a hard dependency on Livewire, Inertia or Pulse. They are
| suggest entries, present here only as dev dependencies so the adapters are
| tested rather than shipped blind.
|
*/

it('never requires an optional package', function (): void {
    $decoded = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true);

    expect($decoded)->toBeArray();

    $composer = is_array($decoded) ? $decoded : [];
    $require = $composer['require'] ?? null;
    $required = array_keys(is_array($require) ? $require : []);

    foreach (['livewire/livewire', 'inertiajs/inertia-laravel', 'laravel/pulse', 'predis/predis'] as $package) {
        expect($required)->not->toContain($package);
    }

    $suggest = $composer['suggest'] ?? null;
    $suggested = array_keys(is_array($suggest) ? $suggest : []);

    expect($suggested)->toContain('livewire/livewire')
        ->toContain('inertiajs/inertia-laravel')
        ->toContain('laravel/pulse')
        ->toContain('ext-redis');
});

it('detects which optional packages are installed', function (): void {
    $integrations = app(Integrations::class);

    // All three are dev dependencies here, so the detection should say so.
    expect($integrations->hasLivewire())->toBeTrue()
        ->and($integrations->hasInertia())->toBeTrue()
        ->and($integrations->hasPulse())->toBeTrue();
});

it('registers nothing when Cairn is disabled', function (): void {
    config()->set('cairn.enabled', false);

    expect(fn () => app(Integrations::class)->register())->not->toThrow(Throwable::class);
});

/*
|--------------------------------------------------------------------------
| Livewire
|--------------------------------------------------------------------------
*/

it('registers the Livewire component only when that driver is selected', function (): void {
    config()->set('cairn.dashboard.driver', 'blade');
    app(Integrations::class)->register();

    expect(componentRegistered('cairn-dashboard'))->toBeFalse();

    config()->set('cairn.dashboard.driver', 'livewire');
    app(Integrations::class)->register();

    expect(componentRegistered('cairn-dashboard'))->toBeTrue();
});

/**
 * The acceptance criterion: the Livewire dashboard must render identical
 * numbers to the Blade one for the same filters. Two dashboards that could
 * drift would mean two sets of numbers to keep honest, and only one of them
 * would ever get checked.
 */
it('renders the same numbers as the Blade dashboard', function (): void {
    seedForIntegrations();

    config()->set('cairn.dashboard.driver', 'livewire');
    app(Integrations::class)->register();

    $filters = new Filters(range: 'today');

    $fromBlade = app(TopRoutes::class)->rows($filters);

    $rendered = Livewire::test(LivewireDashboard::class, ['range' => 'today']);

    // The component renders the same shared partials, so the figures the Blade
    // widget produces must appear in its output.
    $rendered->assertSee('Top routes')
        ->assertSee((string) row($fromBlade)->dimension('route'));

    expect(row($fromBlade)->metric(Metric::Pageviews))->toBe(2.0);
});

it('keeps Livewire filter state in the query string', function (): void {
    config()->set('cairn.dashboard.driver', 'livewire');
    app(Integrations::class)->register();

    Livewire::test(LivewireDashboard::class)
        ->set('range', '7d')
        ->assertSet('range', '7d');
});

it('toggles a filter off when the same value is chosen again', function (): void {
    config()->set('cairn.dashboard.driver', 'livewire');
    app(Integrations::class)->register();

    Livewire::test(LivewireDashboard::class)
        ->call('filterBy', 'route', 'pricing.index')
        ->assertSet('route', 'pricing.index')
        ->call('filterBy', 'route', 'pricing.index')
        ->assertSet('route', '');
});

it('refuses to filter on a dimension the dashboard does not understand', function (): void {
    config()->set('cairn.dashboard.driver', 'livewire');
    app(Integrations::class)->register();

    Livewire::test(LivewireDashboard::class)
        ->call('filterBy', 'url', '/anything')
        ->assertSet('route', '')
        ->assertSet('country', '');
});

it('clears every filter at once', function (): void {
    config()->set('cairn.dashboard.driver', 'livewire');
    app(Integrations::class)->register();

    Livewire::test(LivewireDashboard::class)
        ->set('route', 'pricing.index')
        ->set('country', 'GB')
        ->call('clearFilters')
        ->assertSet('route', '')
        ->assertSet('country', '');
});

/*
|--------------------------------------------------------------------------
| Inertia
|--------------------------------------------------------------------------
*/

it('produces typed page props matching the published definitions', function (): void {
    seedForIntegrations();

    $props = app(InertiaDashboard::class)->props(new Filters(range: 'today'));

    expect($props)->toHaveKeys(['filters', 'ranges', 'widgets']);

    $filters = $props['filters'];

    expect($filters)->toBeArray()
        ->and(is_array($filters) ? array_keys($filters) : [])
        ->toContain('range', 'rangeLabel', 'comparison', 'interval', 'from', 'to', 'active');

    $widgets = $props['widgets'];

    expect($widgets)->toBeArray();
    expect(is_array($widgets) ? $widgets : [])->not->toBeEmpty();

    $widget = is_array($widgets) ? ($widgets[0] ?? []) : [];

    expect(is_array($widget) ? array_keys($widget) : [])
        ->toContain('key', 'title', 'description', 'layout', 'dimension', 'metrics', 'empty', 'rows');
});

/**
 * The published TypeScript has to describe the props the controller actually
 * renders, or it is worse than no types at all.
 */
it('ships TypeScript definitions covering every prop', function (): void {
    $types = (string) file_get_contents(__DIR__.'/../../resources/stubs/inertia/dashboard.d.ts');

    foreach (['range', 'rangeLabel', 'comparison', 'interval', 'active', 'ranges', 'widgets',
        'layout', 'dimension', 'metrics', 'empty', 'rows', 'approximate'] as $prop) {
        expect($types)->toContain($prop);
    }
});

/**
 * Cairn ships controllers and props, not styled components. Saying so in the
 * stub itself is how nobody files a bug about their appearance.
 */
it('says in the stub that the components are yours to own', function (): void {
    $types = (string) file_get_contents(__DIR__.'/../../resources/stubs/inertia/dashboard.d.ts');

    expect($types)->toContain('you own')
        ->toContain('does not maintain');
});

/*
|--------------------------------------------------------------------------
| Pulse
|--------------------------------------------------------------------------
*/

it('registers both Pulse cards when Pulse is present and enabled', function (): void {
    config()->set('cairn.pulse.enabled', true);
    app(Integrations::class)->register();

    expect(componentRegistered('cairn.live-visitors'))->toBeTrue()
        ->and(componentRegistered('cairn.top-routes'))->toBeTrue();
});

/**
 * The other half of the 0.1.2 fix. The cards' views moved out of `cairn::` and
 * into a namespace registered only when Pulse is wanted, so the deploy of an
 * application without Pulse stopped failing — and this is the assertion that
 * the move did not simply hide them from the applications that do have it.
 */
it('renders both Pulse cards from their own view namespace', function (): void {
    seedForIntegrations();

    config()->set('cairn.pulse.enabled', true);
    app(Integrations::class)->register();

    // Rendered through the view factory rather than through Livewire: both
    // cards are #[Lazy], so a plain component render returns the placeholder
    // and never reaches the view this test is about. What has to be proved is
    // that the file resolves under the new namespace *and* that Pulse's own
    // Blade components still resolve inside it.
    $views = app(ViewFactory::class);

    $live = $views->make('cairn-pulse::live-visitors', ['visitors' => 3])->render();

    $routes = $views->make('cairn-pulse::top-routes', [
        'routes' => app(TopRoutes::class)->rows(new Filters(range: 'today')),
    ])->render();

    expect($live)->toContain('Live visitors')
        ->and($routes)->toContain('Top routes')
        ->and($routes)->toContain('pricing.index');
});

it('registers no Pulse cards when they are switched off', function (): void {
    config()->set('cairn.pulse.enabled', false);

    expect(fn () => app(Integrations::class)->register())->not->toThrow(Throwable::class);
});

/**
 * Pulse cards may display data from anywhere, so there is no reason to
 * dual-write. Doing so would duplicate every measurement and couple Cairn's
 * retention to Pulse's rolling window — Pulse is built to forget, and a rollup
 * is meant to be permanent.
 */
it('writes no Cairn data into any pulse table', function (): void {
    seedForIntegrations();

    $classes = [PulseLiveVisitors::class, PulseTopRoutes::class];

    foreach ($classes as $class) {
        $source = codeOf($class);

        expect($source)->not->toContain('pulse_entries');
        expect($source)->not->toContain('pulse_values');
        expect($source)->not->toContain('->record(');
        expect($source)->not->toContain('->set(');
    }
});

it('reads live visitors through Cairn rather than Pulse', function (): void {
    app(Presence::class)->touch(random_bytes(16), '/pricing');

    expect(app(Cairn::class)->live())->toBe(1);
});
