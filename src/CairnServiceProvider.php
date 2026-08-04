<?php

declare(strict_types=1);

namespace Divoto\Cairn;

use Divoto\Cairn\Cairn as CairnManager;
use Divoto\Cairn\Commands\DoctorCommand;
use Divoto\Cairn\Commands\ExportCommand;
use Divoto\Cairn\Commands\ForgetCommand;
use Divoto\Cairn\Commands\GeoipCommand;
use Divoto\Cairn\Commands\PartitionCommand;
use Divoto\Cairn\Commands\PruneCommand;
use Divoto\Cairn\Commands\RollupCommand;
use Divoto\Cairn\Commands\WorkCommand;
use Divoto\Cairn\Consent\GrantingConsentResolver;
use Divoto\Cairn\Contracts\BotDetector;
use Divoto\Cairn\Contracts\ConsentResolver;
use Divoto\Cairn\Contracts\DeviceDetector;
use Divoto\Cairn\Contracts\GeoResolver;
use Divoto\Cairn\Contracts\Ingest;
use Divoto\Cairn\Contracts\Presence;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Contracts\TenantResolver;
use Divoto\Cairn\Contracts\UniqueCounter;
use Divoto\Cairn\Counting\DatabaseUniqueCounter;
use Divoto\Cairn\Counting\NullUniqueCounter;
use Divoto\Cairn\Counting\RedisUniqueCounter;
use Divoto\Cairn\Detection\UserAgentBotDetector;
use Divoto\Cairn\Detection\UserAgentDeviceDetector;
use Divoto\Cairn\Geo\NullGeoResolver;
use Divoto\Cairn\Http\Middleware\Authorize;
use Divoto\Cairn\Http\Middleware\EnsureApiEnabled;
use Divoto\Cairn\Http\Middleware\TrackPageView;
use Divoto\Cairn\Identity\SessionResolver;
use Divoto\Cairn\Identity\VisitorHasher;
use Divoto\Cairn\Ingest\DatabaseIngest;
use Divoto\Cairn\Ingest\NullIngest;
use Divoto\Cairn\Ingest\RedisIngest;
use Divoto\Cairn\Integrations\Integrations;
use Divoto\Cairn\Maintenance\Doctor;
use Divoto\Cairn\Maintenance\Eraser;
use Divoto\Cairn\Maintenance\Maintenance;
use Divoto\Cairn\Maintenance\Pruner;
use Divoto\Cairn\Presence\DatabasePresence;
use Divoto\Cairn\Presence\NullPresence;
use Divoto\Cairn\Presence\RedisPresence;
use Divoto\Cairn\Privacy\IpAnonymiser;
use Divoto\Cairn\Privacy\OptOut;
use Divoto\Cairn\Privacy\PrivacyGate;
use Divoto\Cairn\Recorders\ClientMetrics;
use Divoto\Cairn\Recorders\PageViews;
use Divoto\Cairn\Recording\EntryFactory;
use Divoto\Cairn\Storage\DatabaseStorage;
use Divoto\Cairn\Storage\NullStorage;
use Divoto\Cairn\Support\ChannelClassifier;
use Divoto\Cairn\Support\RouteNameGrouper;
use Divoto\Cairn\Tenancy\NullTenantResolver;
use Divoto\Cairn\Widgets\WidgetRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;

/**
 * The single service provider for the Cairn package.
 *
 * Registered automatically by Laravel package discovery. Everything Cairn adds
 * to the host application is wired up from here — and every optional
 * integration (Livewire, Inertia, Pulse) is registered only when the relevant
 * package is present, detected via class_exists().
 *
 * CLAUDE.md: this class must never make Cairn's failure the host
 * application's failure. Registration is cheap and side-effect free; recording
 * happens later, in a terminating callback.
 */
final class CairnServiceProvider extends ServiceProvider
{
    /**
     * Absolute path to the packaged configuration file.
     */
    private const CONFIG_PATH = __DIR__.'/../config/cairn.php';

    /**
     * Absolute path to the packaged migrations.
     */
    private const MIGRATIONS_PATH = __DIR__.'/../database/migrations';

    /**
     * Absolute path to the packaged Blade views.
     */
    private const VIEWS_PATH = __DIR__.'/../resources/views';

    /**
     * Absolute path to the pre-built dashboard assets.
     */
    private const ASSETS_PATH = __DIR__.'/../resources/dist';

    /**
     * Absolute path to the dashboard routes.
     */
    private const ROUTES_PATH = __DIR__.'/../routes/dashboard.php';

    /**
     * Absolute path to the beacon's route.
     */
    private const COLLECT_ROUTES_PATH = __DIR__.'/../routes/collect.php';

    /**
     * Absolute path to the JSON API's routes.
     */
    private const API_ROUTES_PATH = __DIR__.'/../routes/api.php';

    /**
     * Absolute path to the beacon source and its minified build.
     */
    private const JS_PATH = __DIR__.'/../resources/js';

    /**
     * Absolute path to the publishable stubs.
     */
    private const STUBS_PATH = __DIR__.'/../resources/stubs';

    /**
     * Whether Cairn should run its own migrations from the package.
     *
     * Left true, the packaged migrations run on `php artisan migrate` with no
     * publishing step. A deployer who publishes them in order to customise the
     * schema must call {@see self::ignoreMigrations()} from their own service
     * provider, or the same tables would be created twice under two different
     * migration names.
     */
    public static bool $runsMigrations = true;

    /**
     * Stop Cairn running its packaged migrations.
     *
     * Call this from a service provider's `register` method after publishing
     * the migrations with `--tag=cairn-migrations`.
     */
    public static function ignoreMigrations(): void
    {
        self::$runsMigrations = false;
    }

    /**
     * Every contract Cairn binds, mapped to the implementation used when the
     * package is disabled or when no driver is available for the capability.
     *
     * Phase 4 adds the real ingest, storage, counting and presence drivers;
     * Phase 5 adds the real detectors. Until then every binding resolves to a
     * no-op, which is what lets the container be exercised end to end before
     * any behaviour exists.
     *
     * @var array<class-string, class-string>
     */
    private const FALLBACKS = [
        Ingest::class => NullIngest::class,
        Storage::class => NullStorage::class,
        UniqueCounter::class => NullUniqueCounter::class,
        Presence::class => NullPresence::class,
        GeoResolver::class => NullGeoResolver::class,
        BotDetector::class => UserAgentBotDetector::class,
        DeviceDetector::class => UserAgentDeviceDetector::class,
        TenantResolver::class => NullTenantResolver::class,
        ConsentResolver::class => GrantingConsentResolver::class,
    ];

    /**
     * Implementations selected by `cairn.driver`, per capability.
     *
     * A capability with no entry for the configured driver falls back to its
     * no-op. Storage is deliberately database-only: `cairn.driver` selects
     * where entries are *buffered*, counted and tracked, not where they are
     * durably stored, and there is one storage driver in v1.
     *
     * @var array<class-string, array<string, class-string>>
     */
    private const DRIVERS = [
        Ingest::class => [
            'database' => DatabaseIngest::class,
            'redis' => RedisIngest::class,
        ],
        Storage::class => [
            'database' => DatabaseStorage::class,
            'redis' => DatabaseStorage::class,
        ],
        UniqueCounter::class => [
            'database' => DatabaseUniqueCounter::class,
            'redis' => RedisUniqueCounter::class,
        ],
        Presence::class => [
            'database' => DatabasePresence::class,
            'redis' => RedisPresence::class,
        ],
    ];

    /**
     * Contracts whose implementation is named directly in configuration
     * rather than selected by driver, mapped to the config key holding the
     * class name.
     *
     * @var array<class-string, string>
     */
    private const CONFIGURED = [
        TenantResolver::class => 'cairn.tenancy.resolver',
        ConsentResolver::class => 'cairn.privacy.consent_resolver',
        GeoResolver::class => 'cairn.privacy.geo_resolver',
    ];

    /**
     * Register Cairn's container bindings.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'cairn');

        $this->registerContracts();
        $this->registerIdentity();
    }

    /**
     * Bind the identity and privacy services.
     *
     * These are concrete classes rather than contracts: there is one correct
     * way to derive a rotating visitor hash, and making it swappable would
     * mean offering a seam through which the rotation could be removed.
     */
    private function registerIdentity(): void
    {
        $this->app->singleton(VisitorHasher::class, function (): VisitorHasher {
            $config = $this->app->make(Repository::class);
            $store = $config->get('cairn.cache_store');

            return new VisitorHasher(
                $this->app->make(CacheFactory::class)->store(is_string($store) && $store !== '' ? $store : null),
                $config,
            );
        });

        $this->app->singleton(SessionResolver::class);
        $this->app->singleton(IpAnonymiser::class);
        $this->app->singleton(OptOut::class);
        $this->app->singleton(PrivacyGate::class);
        $this->app->singleton(ChannelClassifier::class);
        $this->app->singleton(RouteNameGrouper::class);
        $this->app->singleton(EntryFactory::class);
        $this->app->singleton(CairnManager::class);

        // Laravel resolves terminable middleware from the container again for
        // terminate(). Without this binding, handle() and terminate() run on
        // different instances and the request timer is silently lost — which
        // is exactly what happened the first time Cairn recorded real traffic.
        $this->app->singleton(TrackPageView::class);
        $this->app->singleton(Pruner::class);
        $this->app->singleton(Doctor::class);
        $this->app->singleton(Eraser::class);
        $this->app->singleton(Maintenance::class);
        $this->app->singleton(WidgetRegistry::class);
    }

    /**
     * Register the pageview middleware on the web group.
     *
     * Pushed rather than prepended, so it wraps as little as possible, and
     * registered only when the recorder is enabled. It records from
     * `terminate()`, so its position in the stack costs the visitor nothing.
     */
    private function registerMiddleware(): void
    {
        if ($this->app->make(Repository::class)->get('cairn.enabled') !== true) {
            return;
        }

        if ($this->app->make(Repository::class)->get('cairn.recorders.'.PageViews::class.'.enabled') === false) {
            return;
        }

        if (! $this->app->bound(Kernel::class)) {
            return;
        }

        $kernel = $this->app->make(Kernel::class);

        if ($kernel instanceof \Illuminate\Foundation\Http\Kernel) {
            $kernel->appendMiddlewareToGroup('web', TrackPageView::class);
        }
    }

    /**
     * Bootstrap Cairn's publishable resources.
     *
     * Publishing is registered unconditionally — a deployer must be able to
     * publish and inspect the config file even with `cairn.enabled` false.
     */
    public function boot(): void
    {
        if (self::$runsMigrations) {
            $this->loadMigrationsFrom(self::MIGRATIONS_PATH);
        }

        $this->registerMiddleware();
        $this->registerGate();
        $this->registerDashboard();
        $this->registerBeacon();
        $this->registerIntegrations();
        $this->registerApi();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::CONFIG_PATH => $this->app->configPath('cairn.php'),
            ], 'cairn-config');

            $this->publishMigrations();

            $this->commands([
                DoctorCommand::class,
                ExportCommand::class,
                ForgetCommand::class,
                GeoipCommand::class,
                PartitionCommand::class,
                PruneCommand::class,
                RollupCommand::class,
                WorkCommand::class,
            ]);

            $this->registerSchedule();
        }
    }

    /**
     * Register the beacon's endpoint and its Blade directive.
     *
     * The endpoint is public where the dashboard is gated, which is why it
     * validates as strictly as it does — see CollectController.
     */
    private function registerBeacon(): void
    {
        $config = $this->app->make(Repository::class);

        // The directive is always defined, so a template using @cairn does not
        // break when the beacon is switched off — it simply renders nothing.
        $this->app->make(BladeCompiler::class)->directive(
            'cairn',
            static fn (): string => "<?php echo \Divoto\Cairn\Support\Beacon::tag(); ?>",
        );

        if ($config->get('cairn.enabled') !== true) {
            return;
        }

        if ($config->get('cairn.recorders.'.ClientMetrics::class.'.enabled') === false) {
            return;
        }

        $middleware = $config->get('cairn.dashboard.middleware');
        $path = $config->get('cairn.dashboard.path');

        $this->app->make(Registrar::class)->group([
            'prefix' => is_string($path) && $path !== '' ? $path : 'cairn',
            'middleware' => is_array($middleware) ? $middleware : ['web'],
            'as' => 'cairn.',
        ], function (): void {
            $this->loadRoutesFrom(self::COLLECT_ROUTES_PATH);
        });
    }

    /**
     * Register the JSON API, if it has been switched on.
     *
     * Off by default: it can read everything the dashboard can, and a package
     * cannot know who is allowed to call it.
     */
    private function registerApi(): void
    {
        $config = $this->app->make(Repository::class);

        if ($config->get('cairn.enabled') !== true) {
            return;
        }

        $middleware = $config->get('cairn.api.middleware');
        $path = $config->get('cairn.dashboard.path');

        // Registered whether or not the API is enabled, and guarded per
        // request by EnsureApiEnabled. Toggling the setting then takes effect
        // without a route-cache rebuild, and a disabled endpoint 404s — to
        // anybody who has not been told it exists, indistinguishable from one
        // that was never built.
        $this->app->make(Registrar::class)->group([
            'prefix' => (is_string($path) && $path !== '' ? $path : 'cairn').'/api',
            'middleware' => [
                ...(is_array($middleware) ? $middleware : ['api']),
                EnsureApiEnabled::class,
            ],
            'as' => 'cairn.',
        ], function (): void {
            $this->loadRoutesFrom(self::API_ROUTES_PATH);
        });
    }

    /**
     * Register the optional integrations.
     *
     * Delegated to the Integrations namespace, which is the only place in the
     * package permitted to name Livewire, Inertia or Pulse — an architecture
     * test enforces that boundary.
     */
    private function registerIntegrations(): void
    {
        $integrations = $this->app->make(Integrations::class);

        // The Pulse cards' views, and only when the cards themselves are being
        // registered. They use Pulse's own Blade components, so a namespace
        // registered unconditionally would break `view:cache` — and therefore
        // the deployment — of every application that has Cairn without Pulse.
        //
        // Registered here rather than alongside the components because
        // `loadViewsFrom` is what makes a published override win: it looks in
        // the application's own `resources/views/vendor` first, which a bare
        // `addNamespace` would skip, silently ignoring anything published
        // under the `cairn-pulse-views` tag.
        if ($integrations->wantsPulse()) {
            $this->loadViewsFrom(Integrations::PULSE_VIEWS_PATH, 'cairn-pulse');
        }

        $integrations->register();
    }

    /**
     * Define the dashboard authorisation gate.
     *
     * Defined only if the application has not defined it, so a deployer's own
     * `viewCairn` gate always wins.
     *
     * The default denies everybody outside the local environment — the same
     * stance Telescope and Pulse take, and the right one: an analytics
     * dashboard reachable by anybody who guesses the URL is a data leak, and a
     * package cannot know who is allowed to see it.
     */
    private function registerGate(): void
    {
        $this->callAfterResolving(Gate::class, function (Gate $gate): void {
            if ($gate->has('viewCairn')) {
                return;
            }

            $gate->define('viewCairn', fn (mixed $user = null): bool => $this->app->environment('local'));
        });
    }

    /**
     * Register the dashboard's routes, views and publishable assets.
     */
    private function registerDashboard(): void
    {
        $this->loadViewsFrom(self::VIEWS_PATH, 'cairn');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::VIEWS_PATH => $this->app->resourcePath('views/vendor/cairn'),
            ], 'cairn-views');

            // The Pulse cards publish under their own tag, and deliberately not
            // as part of `cairn-views`. They are the only views Cairn ships
            // that cannot be compiled without an optional package installed, so
            // publishing them into an application without Pulse would break
            // `view:cache` — the same failure the separate namespace exists to
            // prevent. Nobody asks for this tag by accident.
            $this->publishes([
                Integrations::PULSE_VIEWS_PATH => $this->app->resourcePath('views/vendor/cairn-pulse'),
            ], 'cairn-pulse-views');

            $this->publishes([
                self::ASSETS_PATH => $this->app->publicPath('vendor/cairn'),
                self::JS_PATH => $this->app->publicPath('vendor/cairn/src'),
            ], 'cairn-assets');

            // A privacy-notice template and an opt-out controller. Both are
            // starting points the deployer owns — the wording, the routes and
            // the redirect targets belong to their application, and the notice
            // is explicitly not legal advice.
            // Starting points the deployer owns. Cairn does not maintain the
            // appearance of framework components inside somebody else's design
            // system, and says so in the README.
            $this->publishes([
                self::STUBS_PATH.'/inertia' => $this->app->resourcePath('js/cairn'),
            ], 'cairn-inertia');

            $this->publishes([
                self::STUBS_PATH.'/privacy-notice.md' => $this->app->basePath('resources/cairn/privacy-notice.md'),
                self::STUBS_PATH.'/opt-out-controller.stub' => $this->app->basePath('app/Http/Controllers/OptOutController.php'),
            ], 'cairn-privacy');
        }

        $config = $this->app->make(Repository::class);

        if ($config->get('cairn.dashboard.enabled') !== true) {
            return;
        }

        if ($config->get('cairn.dashboard.driver') === 'none') {
            return;
        }

        $middleware = $config->get('cairn.dashboard.middleware');
        $path = $config->get('cairn.dashboard.path');

        $this->app->make(Registrar::class)->group([
            'domain' => null,
            'prefix' => is_string($path) && $path !== '' ? $path : 'cairn',
            'middleware' => is_array($middleware) ? [...$middleware, Authorize::class] : ['web', Authorize::class],
            'as' => 'cairn.',
        ], function (): void {
            $this->loadRoutesFrom(self::ROUTES_PATH);
        });
    }

    /**
     * Schedule Cairn's own maintenance.
     *
     * Registered only when the deployer has not already scheduled these
     * commands themselves — otherwise a user following the documentation and
     * adding them to their own schedule would silently run everything twice.
     */
    private function registerSchedule(): void
    {
        if ($this->app->make(Repository::class)->get('cairn.enabled') !== true) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if ($this->alreadyScheduled($schedule, 'cairn:rollup')) {
                return;
            }

            // Hourly rather than per-minute: the lottery keeps the current
            // day's buckets fresh between runs, and this is the repair pass.
            $schedule->command('cairn:rollup --period=all')
                ->hourly()
                ->withoutOverlapping()
                ->runInBackground();

            if (! $this->alreadyScheduled($schedule, 'cairn:prune')) {
                $schedule->command('cairn:prune')
                    ->dailyAt('03:10')
                    ->withoutOverlapping()
                    ->runInBackground();
            }
        });
    }

    /**
     * Whether the application has already scheduled a Cairn command.
     */
    private function alreadyScheduled(Schedule $schedule, string $command): bool
    {
        foreach ($schedule->events() as $event) {
            if (str_contains($event->command ?? '', $command)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Register the packaged migrations for publishing.
     *
     * Published files are stamped with the time of publishing, following the
     * convention every Laravel package uses, so that they sort after the host
     * application's existing migrations.
     */
    private function publishMigrations(): void
    {
        $migrations = glob(self::MIGRATIONS_PATH.'/*.php') ?: [];
        sort($migrations);

        $paths = [];
        $now = time();

        foreach ($migrations as $index => $migration) {
            // Strip the package's own date prefix and restamp, so published
            // files sort after whatever the host application already has.
            // Each gets its own second to preserve the order they ship in.
            $name = (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($migration));

            $paths[$migration] = $this->app->databasePath(
                'migrations/'.date('Y_m_d_His', $now + $index).'_'.$name
            );
        }

        $this->publishes($paths, 'cairn-migrations');
    }

    /**
     * The contracts this provider guarantees are resolvable.
     *
     * Declared so the container can defer to them and so a test can assert the
     * full set without reaching into private state.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return array_keys(self::FALLBACKS);
    }

    /**
     * Bind every Cairn contract as a singleton.
     *
     * Each binding is resolved lazily, so a misconfigured driver class only
     * fails when that capability is actually used — not at boot, where it
     * would take the host application down with it.
     */
    private function registerContracts(): void
    {
        foreach (self::FALLBACKS as $contract => $fallback) {
            $this->app->singleton(
                $contract,
                fn (): object => $this->app->make($this->implementationFor($contract, $fallback)),
            );
        }
    }

    /**
     * Work out which class should satisfy a contract.
     *
     * When Cairn is disabled every contract resolves to its no-op fallback, so
     * a disabled installation cannot record, count or store anything even if
     * some code path tries.
     *
     * @param  class-string  $contract
     * @param  class-string  $fallback
     * @return class-string
     */
    private function implementationFor(string $contract, string $fallback): string
    {
        $config = $this->app->make(Repository::class);

        if ($config->get('cairn.enabled') !== true) {
            return $fallback;
        }

        if (isset(self::CONFIGURED[$contract])) {
            $configured = $config->get(self::CONFIGURED[$contract]);

            return is_string($configured) && $configured !== '' && class_exists($configured)
                ? $configured
                : $fallback;
        }

        $driver = $config->get('cairn.driver');

        if (! is_string($driver)) {
            return $fallback;
        }

        return self::DRIVERS[$contract][$driver] ?? $fallback;
    }
}
