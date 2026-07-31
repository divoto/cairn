<?php

declare(strict_types=1);

namespace Divoto\Cairn\Tests;

use Divoto\Cairn\CairnServiceProvider;
use Illuminate\Foundation\Application;
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

        // The driver-parity suite exercises the Redis drivers against a real
        // server. A conditionally-skipped driver is a driver nobody notices
        // breaking, so this is configured rather than optional.
        $config->set('database.redis.client', 'phpredis');
        $config->set('database.redis.default', [
            'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('REDIS_PORT') ?: 6379),
            'password' => getenv('REDIS_PASSWORD') ?: null,
            // A high database number, so a developer running the suite against
            // a shared Redis does not flush their application's keys.
            'database' => (int) (getenv('REDIS_DB') ?: 15),
        ]);

        $config->set('cairn.enabled', true);
    }
}
