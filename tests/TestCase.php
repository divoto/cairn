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

        $config->set('cairn.enabled', true);
    }
}
