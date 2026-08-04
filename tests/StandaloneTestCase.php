<?php

declare(strict_types=1);

namespace Divoto\Cairn\Tests;

use Divoto\Cairn\CairnServiceProvider;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Foundation\Application;

/**
 * A test application with Cairn and nothing else.
 *
 * The base {@see TestCase} registers Livewire and Pulse so the optional
 * adapters are exercised rather than shipped blind, which makes it the wrong
 * place to prove Cairn works *without* them: an application that has every
 * optional package installed cannot fail the way a real one does.
 *
 * Composer cannot uninstall a dev dependency for the duration of one test, so
 * `class_exists()` still answers true here and the detection has to be told
 * otherwise. The Pulse switch is what does it: it reaches exactly the same
 * early return that a missing package reaches, which is the state being
 * tested — Cairn asked whether the integration was wanted and was told no.
 */
abstract class StandaloneTestCase extends TestCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [CairnServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->make(Config::class)->set('cairn.pulse.enabled', false);
        $app->make(Config::class)->set('cairn.dashboard.driver', 'blade');
    }
}
