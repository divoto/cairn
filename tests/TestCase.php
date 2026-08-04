<?php

declare(strict_types=1);

namespace Divoto\Cairn\Tests;

use Divoto\Cairn\CairnServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use Laravel\Pulse\PulseServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base test case for the Cairn suite.
 *
 * CLAUDE.md: the suite runs against SQLite by default; MySQL, MariaDB and
 * PostgreSQL are exercised by separate CI jobs that override DB_CONNECTION.
 */
abstract class TestCase extends Orchestra
{
    /**
     * Register the package's service provider with the testbench application.
     *
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            // Livewire and Pulse are dev dependencies only, present so the
            // optional adapters are tested rather than shipped blind. Cairn
            // itself never requires them.
            LivewireServiceProvider::class,
            PulseServiceProvider::class,
            CairnServiceProvider::class,
        ];
    }

    /**
     * Configure the testbench application before it boots.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $config = $app->make('config');

        // An in-memory SQLite database keeps the default suite fast and
        // dependency-free. CI overrides this per database engine.
        if ($config->get('database.default') === 'testing') {
            $config->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
        }

        // The web middleware group encrypts cookies, which needs a key.
        $config->set('app.key', 'base64:Y2Fpcm4tdGVzdC1rZXktMzItYnl0ZXMtZXhhY3RseSE=');

        // The driver-parity suite exercises the Redis drivers against a real
        // server. A conditionally-skipped driver is a driver nobody notices
        // breaking, so this is configured rather than optional.
        // Read through Env rather than getenv(): a .env writes booleans and
        // nulls as the words "true" and "null", and Env applies the casting
        // that turns them back into values. getenv() would hand phpredis the
        // four-character string "null" as a password and it would dutifully
        // send AUTH to a server that has none.
        $config->set('database.redis.client', 'phpredis');
        $config->set('database.redis.default', [
            'host' => Env::get('REDIS_HOST') ?: '127.0.0.1',
            'port' => (int) (Env::get('REDIS_PORT') ?: 6379),
            'password' => Env::get('REDIS_PASSWORD') ?: null,
            // A high database number, so a developer running the suite against
            // a shared Redis does not flush their application's keys.
            'database' => (int) (Env::get('REDIS_DB') ?: 15),
        ]);

        $config->set('cairn.enabled', true);

        // The shipped default is ['api', 'auth:sanctum']; sanctum is not
        // installed here. ConfigurationTest asserts the real default — this
        // only makes the routes resolvable in the test application.
        $config->set('cairn.api.middleware', ['api']);
    }
}
