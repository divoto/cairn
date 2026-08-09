<?php

declare(strict_types=1);

use Divoto\Cairn\CairnServiceProvider;
use Divoto\Cairn\Integrations\Integrations;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\FileViewFinder;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Deployment
|--------------------------------------------------------------------------
|
| What a deployment does to a package, which is not what a test suite does.
| `php artisan view:cache` and `php artisan config:cache` are in Forge's
| default deploy script and in most Dockerfiles, so they run against every
| installation — including the ones that took none of the optional packages.
|
| These are cheap, and they catch the one class of bug that can only ever
| appear in somebody else's deploy log: a build-time step that walks
| everything the package registered, with no regard for whether it was
| switched on.
|
*/

afterEach(function (): void {
    app(Kernel::class)->call('view:clear');
});

/**
 * The 0.1.2 regression, stated as the rule it broke.
 *
 * The `cairn::` namespace is registered unconditionally — it has to be, the
 * dashboard is the package — and `view:cache` compiles every Blade file in
 * every registered path, ignoring configuration entirely. So every view in
 * this directory must compile in an application that installed nothing but
 * Cairn. Two Pulse cards did not: they use Pulse's own `<x-pulse::card>`
 * components, and until 0.1.2 they lived here, which failed the deploy of
 * every application that had Cairn without Pulse. The runtime guard was
 * correct and never reached — nothing was rendering, something was compiling.
 */
it('compiles every unconditionally registered view with no optional package present', function (): void {
    $compiler = app(BladeCompiler::class);

    $failures = [];

    foreach (Finder::create()->in(__DIR__.'/../../resources/views')->name('*.blade.php')->files() as $file) {
        try {
            $compiler->compileString((string) file_get_contents($file->getPathname()));
        } catch (Throwable $e) {
            $failures[] = $file->getRelativePathname().': '.$e->getMessage();
        }
    }

    expect($failures)->toBe([]);
});

it('caches its views in an application with none of the optional packages', function (): void {
    expect(app(Kernel::class)->call('view:cache'))->toBe(0)
        ->and(app(Kernel::class)->output())->not->toContain('Unable to locate');
});

it('caches the configuration', function (): void {
    expect(app(Kernel::class)->call('config:cache'))->toBe(0);

    app(Kernel::class)->call('config:clear');
});

/**
 * The Pulse cards must be unreachable through the always-on namespace, or
 * moving the files bought nothing.
 */
it('exposes the Pulse cards only through their own namespace', function (): void {
    $views = app(ViewFactory::class);
    $finder = $views->getFinder();

    expect($views->exists('cairn::pulse.live-visitors'))->toBeFalse()
        ->and($views->exists('cairn::pulse.top-routes'))->toBeFalse()
        ->and($finder)->toBeInstanceOf(FileViewFinder::class);

    $hints = $finder instanceof FileViewFinder ? $finder->getHints() : [];

    expect($hints)->toHaveKey('cairn')
        ->and($hints)->not->toHaveKey('cairn-pulse');
});

/**
 * They must not travel with the tag that restyles the dashboard either.
 * Publishing them into an application without Pulse would put them back on a
 * compiled path — the application's own this time — and break the same deploy
 * from the other direction.
 */
it('keeps the Pulse cards out of the cairn-views publish tag', function (): void {
    $views = array_keys(CairnServiceProvider::pathsToPublish(CairnServiceProvider::class, 'cairn-views'));
    $pulse = array_keys(CairnServiceProvider::pathsToPublish(CairnServiceProvider::class, 'cairn-pulse-views'));

    expect($views)->not->toContain(Integrations::PULSE_VIEWS_PATH)
        ->and($pulse)->toContain(Integrations::PULSE_VIEWS_PATH);
});
