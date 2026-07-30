<?php

declare(strict_types=1);

namespace Divoto\Cairn;

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
     * Register Cairn's container bindings.
     *
     * Phase 1 binds every contract from config here. For now this merges the
     * package configuration so `config('cairn.enabled')` resolves whether or
     * not the deployer has published the file.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'cairn');
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
}
