<?php

declare(strict_types=1);

namespace Divoto\Cairn;

use Divoto\Cairn\Cairn as CairnManager;
use Divoto\Cairn\Commands\PartitionCommand;
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
use Divoto\Cairn\Http\Middleware\TrackPageView;
use Divoto\Cairn\Identity\SessionResolver;
use Divoto\Cairn\Identity\VisitorHasher;
use Divoto\Cairn\Ingest\DatabaseIngest;
use Divoto\Cairn\Ingest\NullIngest;
use Divoto\Cairn\Ingest\RedisIngest;
use Divoto\Cairn\Presence\DatabasePresence;
use Divoto\Cairn\Presence\NullPresence;
use Divoto\Cairn\Presence\RedisPresence;
use Divoto\Cairn\Privacy\IpAnonymiser;
use Divoto\Cairn\Privacy\PrivacyGate;
use Divoto\Cairn\Recorders\PageViews;
use Divoto\Cairn\Recording\EntryFactory;
use Divoto\Cairn\Storage\DatabaseStorage;
use Divoto\Cairn\Storage\NullStorage;
use Divoto\Cairn\Support\ChannelClassifier;
use Divoto\Cairn\Support\RouteNameGrouper;
use Divoto\Cairn\Tenancy\NullTenantResolver;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;

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

        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::CONFIG_PATH => $this->app->configPath('cairn.php'),
            ], 'cairn-config');

            $this->publishMigrations();

            $this->commands([
                PartitionCommand::class,
            ]);
        }
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
