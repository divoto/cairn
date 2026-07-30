<?php

declare(strict_types=1);

namespace Divoto\Cairn;

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
use Divoto\Cairn\Counting\NullUniqueCounter;
use Divoto\Cairn\Detection\NullBotDetector;
use Divoto\Cairn\Detection\NullDeviceDetector;
use Divoto\Cairn\Geo\NullGeoResolver;
use Divoto\Cairn\Ingest\NullIngest;
use Divoto\Cairn\Presence\NullPresence;
use Divoto\Cairn\Storage\NullStorage;
use Divoto\Cairn\Tenancy\NullTenantResolver;
use Illuminate\Contracts\Config\Repository;
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
        BotDetector::class => NullBotDetector::class,
        DeviceDetector::class => NullDeviceDetector::class,
        TenantResolver::class => NullTenantResolver::class,
        ConsentResolver::class => GrantingConsentResolver::class,
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
    }

    /**
     * Bootstrap Cairn's publishable resources.
     *
     * Publishing is registered unconditionally — a deployer must be able to
     * publish and inspect the config file even with `cairn.enabled` false.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::CONFIG_PATH => $this->app->configPath('cairn.php'),
            ], 'cairn-config');
        }
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

        // Driver-selected contracts have no alternative implementation yet.
        // Phase 4 resolves `cairn.driver` to a class here.
        return $fallback;
    }
}
