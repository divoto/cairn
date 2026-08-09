<?php

declare(strict_types=1);

use Divoto\Cairn\CairnServiceProvider;

it('is discovered and booted by the host application', function (): void {
    expect(app()->getLoadedProviders())
        ->toHaveKey(CairnServiceProvider::class);
});

it('merges the packaged configuration without publishing', function (): void {
    expect(config('cairn.enabled'))->toBeTrue()
        ->and(config('cairn.table_prefix'))->toBe('cairn_');
});

it('publishes the configuration under the cairn-config tag', function (): void {
    $paths = CairnServiceProvider::pathsToPublish(CairnServiceProvider::class, 'cairn-config');

    expect($paths)->toHaveCount(1)
        ->and(array_key_first($paths))->toEndWith('config/cairn.php')
        ->and(reset($paths))->toEndWith('config/cairn.php');
});

it('leaves the host application untouched when disabled', function (): void {
    config()->set('cairn.enabled', false);

    expect(config('cairn.enabled'))->toBeFalse();
});
