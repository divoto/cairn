<?php

declare(strict_types=1);

use Divoto\Cairn\CairnServiceProvider;
use Divoto\Cairn\Contracts\Ingest;
use Divoto\Cairn\Contracts\Presence;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Contracts\UniqueCounter;
use Divoto\Cairn\Recorders\ClientMetrics;
use Divoto\Cairn\Recorders\PageViews;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;

/**
 * Invokes one of the provider's own private methods directly, bypassing the
 * boot() that already ran for this test's application — several branches
 * (a recorder switched off, a route only registered when a setting is on)
 * depend on config the test sets in its own body, which is too late to
 * change what that original boot already decided.
 */
function invokeProvider(string $method): void
{
    $provider = new CairnServiceProvider(app());
    $reflected = new ReflectionMethod($provider, $method);
    $reflected->invoke($provider);
}

/**
 * Runs a provider method against a brand-new router bound in its place, so
 * what it registers (or does not) can be read directly off that router —
 * rather than trying to detect one more route with the same name among
 * everything the test's own application already registered at boot.
 */
function invokeProviderRouting(string $method): Router
{
    $router = new Router(app('events'), app());
    $original = app('router');

    app()->instance('router', $router);

    try {
        invokeProvider($method);
    } finally {
        app()->instance('router', $original);
    }

    return $router;
}

it('is discovered and booted by the host application', function (): void {
    expect(app()->getLoadedProviders())
        ->toHaveKey(CairnServiceProvider::class);
});

it('merges the packaged configuration without publishing', function (): void {
    expect(config('cairn.enabled'))->toBeTrue()
        ->and(config('cairn.table_prefix'))->toBe('cairn_');
});

it('publishes the configuration under the cairn-config tag', function (): void {
    $paths = CairnServiceProvider::pathsToPublish(CairnServiceProvider::class, 'cairn-config');

    expect($paths)->toHaveCount(1)
        ->and(array_key_first($paths))->toEndWith('config/cairn.php')
        ->and(reset($paths))->toEndWith('config/cairn.php');
});

it('leaves the host application untouched when disabled', function (): void {
    config()->set('cairn.enabled', false);

    expect(config('cairn.enabled'))->toBeFalse();
});

it('declares every contract it guarantees is resolvable', function (): void {
    $provider = new CairnServiceProvider(app());

    expect($provider->provides())->toContain(
        Ingest::class,
        Storage::class,
        UniqueCounter::class,
        Presence::class,
    );
});

it('stops running its packaged migrations once told to', function (): void {
    expect(CairnServiceProvider::$runsMigrations)->toBeTrue();

    CairnServiceProvider::ignoreMigrations();

    try {
        expect(CairnServiceProvider::$runsMigrations)->toBeFalse();
    } finally {
        CairnServiceProvider::$runsMigrations = true;
    }
});

/*
|--------------------------------------------------------------------------
| Middleware
|--------------------------------------------------------------------------
*/

it('does not register the pageview middleware when that recorder is switched off', function (): void {
    config()->set('cairn.enabled', true);
    config()->set('cairn.recorders.'.PageViews::class.'.enabled', false);

    $kernel = new class(app(), app('router')) extends Illuminate\Foundation\Http\Kernel
    {
        protected $middlewareGroups = ['web' => []];
    };

    $original = app(Kernel::class);
    app()->instance(Kernel::class, $kernel);

    try {
        invokeProvider('registerMiddleware');

        expect($kernel->getMiddlewareGroups()['web'])->toBe([]);
    } finally {
        app()->instance(Kernel::class, $original);
    }
});

it('does nothing when no HTTP kernel is bound', function (): void {
    config()->set('cairn.enabled', true);
    config()->set('cairn.recorders.'.PageViews::class.'.enabled', true);

    $original = app(Kernel::class);
    unset(app()[Kernel::class]);

    try {
        expect(app()->bound(Kernel::class))->toBeFalse();
        expect(function (): void {
            invokeProvider('registerMiddleware');
        })->not->toThrow(Throwable::class);
    } finally {
        app()->instance(Kernel::class, $original);
    }
});

/*
|--------------------------------------------------------------------------
| Routes only registered when their setting is on
|--------------------------------------------------------------------------
*/

it('does not register the beacon route when that recorder is switched off', function (): void {
    config()->set('cairn.enabled', true);
    config()->set('cairn.recorders.'.ClientMetrics::class.'.enabled', false);

    $router = invokeProviderRouting('registerBeacon');

    expect($router->getRoutes()->hasNamedRoute('cairn.collect'))->toBeFalse();
});

it('does not register the dashboard route when it is switched off', function (): void {
    config()->set('cairn.dashboard.enabled', false);

    $router = invokeProviderRouting('registerDashboard');

    expect($router->getRoutes()->hasNamedRoute('cairn.dashboard'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The dashboard gate
|--------------------------------------------------------------------------
*/

/**
 * A deployer's own `viewCairn` gate always wins. Defined here through the
 * real Gate contract rather than reflection: registerGate() fires its
 * callback immediately when Gate is already resolved, which it is by the
 * time any test body runs.
 */
it('never overrides a viewCairn gate the host application already defined', function (): void {
    Gate::define('viewCairn', fn (mixed $user = null): bool => true);

    invokeProvider('registerGate');

    expect(Gate::allows('viewCairn'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Scheduled maintenance
|--------------------------------------------------------------------------
*/

it('schedules its own maintenance when nothing else has', function (): void {
    $schedule = app(Schedule::class);

    $commands = collect($schedule->events())->pluck('command');

    expect($commands->first(fn (mixed $c): bool => is_string($c) && str_contains($c, 'cairn:rollup')))->not->toBeNull()
        ->and($commands->first(fn (mixed $c): bool => is_string($c) && str_contains($c, 'cairn:prune')))->not->toBeNull();
});

it('does not schedule its own maintenance a second time', function (): void {
    $schedule = new Schedule;
    $schedule->command('cairn:rollup')->hourly();
    $schedule->command('cairn:prune')->dailyAt('03:10');

    app()->instance(Schedule::class, $schedule);

    invokeProvider('registerSchedule');

    $commands = collect($schedule->events())->pluck('command');

    expect($commands->filter(fn (mixed $c): bool => is_string($c) && str_contains($c, 'cairn:rollup')))->toHaveCount(1)
        ->and($commands->filter(fn (mixed $c): bool => is_string($c) && str_contains($c, 'cairn:prune')))->toHaveCount(1);
});
