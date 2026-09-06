<?php

declare(strict_types=1);

use Divoto\Cairn\CairnServiceProvider;
use Divoto\Cairn\Recorders\ClientMetrics;
use Divoto\Cairn\Recorders\Conversions;
use Divoto\Cairn\Recorders\PageViews;

it('exposes every documented configuration key', function (string $key): void {
    expect(config()->has($key))->toBeTrue();
})->with([
    'cairn.enabled',
    'cairn.domain',
    'cairn.connection',
    'cairn.table_prefix',
    'cairn.driver',
    'cairn.redis.connection',
    'cairn.ingest.buffer',
    'cairn.ingest.lottery',
    'cairn.privacy.respect_dnt',
    'cairn.privacy.respect_gpc',
    'cairn.privacy.track_user_id',
    'cairn.privacy.durable_identity',
    'cairn.privacy.salt_rotation_hours',
    'cairn.privacy.geo_precision',
    'cairn.privacy.consent_resolver',
    'cairn.retention.entries',
    'cairn.retention.sessions',
    'cairn.retention.aggregates',
    'cairn.recorders',
    'cairn.dashboard.enabled',
    'cairn.dashboard.driver',
    'cairn.dashboard.path',
    'cairn.dashboard.middleware',
    'cairn.api.enabled',
    'cairn.api.middleware',
    'cairn.tenancy.enabled',
    'cairn.tenancy.resolver',
    'cairn.pulse.enabled',
]);

/**
 * Project rule: personal-data features are opt-in. If either of these ever ships
 * defaulting to true, the package's central claim stops being true.
 */
it('ships both personal-data options disabled', function (): void {
    expect(config('cairn.privacy.track_user_id'))->toBeFalse()
        ->and(config('cairn.privacy.durable_identity'))->toBeFalse();
});

it('honours Do Not Track and Global Privacy Control by default', function (): void {
    expect(config('cairn.privacy.respect_dnt'))->toBeTrue()
        ->and(config('cairn.privacy.respect_gpc'))->toBeTrue();
});

it('rotates the salt every 24 hours by default', function (): void {
    expect(config('cairn.privacy.salt_rotation_hours'))->toBe(24);
});

it('stores no geography finer than country by default', function (): void {
    expect(config('cairn.privacy.geo_precision'))->toBe('country');
});

it('defaults to the database driver so Redis is never required', function (): void {
    expect(config('cairn.driver'))->toBe('database');
});

/**
 * The API can read everything the dashboard can, so the shipped default puts
 * it behind authentication as well as behind a switch.
 */
it('puts the JSON API behind auth:sanctum by default', function (): void {
    $packaged = require __DIR__.'/../../config/cairn.php';

    expect($packaged['api']['enabled'])->toBeFalse()
        ->and($packaged['api']['middleware'])->toBe(['api', 'auth:sanctum']);
});

it('keeps the JSON API and tenancy off until explicitly enabled', function (): void {
    expect(config('cairn.api.enabled'))->toBeFalse()
        ->and(config('cairn.tenancy.enabled'))->toBeFalse();
});

it('keeps rollups forever and raw data briefly', function (): void {
    expect(config('cairn.retention.aggregates'))->toBeNull()
        ->and(config('cairn.retention.entries'))->toBe(30)
        ->and(config('cairn.retention.sessions'))->toBe(30);
});

/**
 * The config file keys recorder options by class name. If one of those classes
 * does not exist, publishing the file fatals on the deployer's machine.
 */
it('references only recorder classes that exist', function (): void {
    $recorders = config('cairn.recorders');

    expect($recorders)->toBeArray();

    foreach (array_keys(is_array($recorders) ? $recorders : []) as $recorder) {
        expect(is_string($recorder))->toBeTrue('Recorder keys must be class names')
            ->and(class_exists((string) $recorder))->toBeTrue("Recorder {$recorder} does not exist");
    }
});

it('registers the three shipped recorders', function (): void {
    expect(config('cairn.recorders'))
        ->toHaveKeys([PageViews::class, ClientMetrics::class, Conversions::class]);
});

it('ignores its own dashboard and the usual tooling paths', function (): void {
    expect(config('cairn.recorders.'.PageViews::class.'.ignore'))
        ->toContain('cairn*')
        ->toContain('telescope*')
        ->toContain('horizon*');
});

it('samples everything by default', function (): void {
    expect(config('cairn.recorders.'.PageViews::class.'.sample_rate'))->toBe(1.0);
});

/**
 * The published file must be valid PHP that returns the same shape as the
 * merged config — a syntax error here only surfaces on a deployer's machine.
 */
it('publishes a file that parses and returns an array', function (): void {
    $published = require __DIR__.'/../../config/cairn.php';

    expect($published)->toBeArray()
        ->and($published)->toHaveKeys(['enabled', 'privacy', 'retention', 'recorders', 'dashboard']);
});

/*
|--------------------------------------------------------------------------
| Merging under a published config file
|--------------------------------------------------------------------------
|
| A published config/cairn.php does not change when the package updates, so
| the merge decides what a deployer who published at 1.0 sees in 1.2. Laravel
| merges top-level keys only, which is how dashboard.widgets came to need a
| hard-coded fallback; these pin the rule that replaces it.
|
*/

/**
 * Re-run the provider's registration over a stand-in published config.
 *
 * @param  array<string, mixed>  $published
 */
function republish(array $published): void
{
    config()->set('cairn', $published);

    (new CairnServiceProvider(app()))->register();
}

it('supplies a nested key a published config predates', function (): void {
    // A published file from before Core Web Vitals and the new panels.
    republish([
        'enabled' => true,
        'privacy' => ['respect_dnt' => false],
    ]);

    expect(config('cairn.privacy.respect_dnt'))->toBeFalse()
        // Never written by this deployer, and now theirs at our default.
        ->and(config('cairn.privacy.respect_gpc'))->toBeTrue()
        ->and(config('cairn.privacy.geo_precision'))->not->toBeNull()
        // A whole top-level block they never had.
        ->and(config('cairn.retention.entries'))->not->toBeNull();
});

/**
 * The failure mode that made Laravel's own recursive helper unusable here:
 * array_replace_recursive merges lists by index, so a trimmed widget list
 * comes back padded out of ours, with a duplicate where the lengths cross.
 */
it('leaves a published list exactly as it was written', function (): void {
    republish([
        'enabled' => true,
        'dashboard' => ['widgets' => ['Overview', 'TopRoutes']],
    ]);

    expect(config('cairn.dashboard.widgets'))->toBe(['Overview', 'TopRoutes']);
});

it('honours an explicitly empty list rather than restoring the defaults', function (): void {
    republish([
        'enabled' => true,
        'dashboard' => ['widgets' => []],
    ]);

    expect(config('cairn.dashboard.widgets'))->toBe([]);
});

/**
 * Choosing to ignore nothing is a choice. Appending our nine patterns back
 * would quietly resume dropping requests the deployer wanted recorded.
 */
it('does not append its own defaults to a published ignore list', function (): void {
    republish([
        'enabled' => true,
        'recorders' => [PageViews::class => ['ignore' => ['admin/*']]],
    ]);

    expect(config('cairn.recorders.'.PageViews::class.'.ignore'))->toBe(['admin/*'])
        // Sibling keys inside the same recorder still fill in.
        ->and(config('cairn.recorders.'.PageViews::class.'.enabled'))->toBeTrue();
});

/**
 * Null is an answer, not an absence. A deployer who wrote it meant it.
 */
it('keeps a published null rather than treating it as unset', function (): void {
    republish([
        'enabled' => true,
        'domain' => null,
    ]);

    expect(config('cairn.domain'))->toBeNull();
});

it('lets a published value win at every depth', function (): void {
    republish([
        'enabled' => false,
        'privacy' => ['maxmind' => ['account_id' => 'mine']],
    ]);

    expect(config('cairn.enabled'))->toBeFalse()
        ->and(config('cairn.privacy.maxmind.account_id'))->toBe('mine');
});

/**
 * A deployer who published before a block existed gets the whole block, which
 * is the case dashboard.widgets needed its own fallback for.
 */
it('supplies a whole block a published config never had', function (): void {
    republish(['enabled' => true]);

    expect(config('cairn.dashboard.widgets'))->toBeArray()
        ->and(config('cairn.dashboard.widgets'))->not->toBe([]);
});
