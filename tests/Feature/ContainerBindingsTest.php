<?php

declare(strict_types=1);

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
use Divoto\Cairn\Ingest\NullIngest;
use Divoto\Cairn\Storage\NullStorage;
use Divoto\Cairn\Tenancy\NullTenantResolver;

/**
 * Phase 1 acceptance: the container resolves every contract.
 */
$contracts = [
    [Ingest::class],
    [Storage::class],
    [UniqueCounter::class],
    [Presence::class],
    [GeoResolver::class],
    [BotDetector::class],
    [DeviceDetector::class],
    [TenantResolver::class],
    [ConsentResolver::class],
];

it('resolves every contract from the container', function (string $contract): void {
    $instance = app($contract);

    expect($instance)->toBeObject()
        ->and(is_a($instance, $contract))->toBeTrue("{$contract} did not resolve to an implementation");
})->with($contracts);

it('resolves each contract as a singleton', function (string $contract): void {
    expect(app($contract))->toBe(app($contract));
})->with($contracts);

it('falls back to no-op implementations while no drivers exist', function (): void {
    expect(app(Ingest::class))->toBeInstanceOf(NullIngest::class)
        ->and(app(Storage::class))->toBeInstanceOf(NullStorage::class);
});

it('binds the no-op drivers when Cairn is disabled', function (): void {
    config()->set('cairn.enabled', false);
    config()->set('cairn.driver', 'redis');

    expect(app(Ingest::class))->toBeInstanceOf(NullIngest::class)
        ->and(app(Storage::class))->toBeInstanceOf(NullStorage::class);
});

it('honours a consent resolver named in configuration', function (): void {
    expect(app(ConsentResolver::class))->toBeInstanceOf(GrantingConsentResolver::class);
});

it('falls back when a configured class does not exist', function (): void {
    config()->set('cairn.tenancy.resolver', 'Divoto\Cairn\NotARealClass');

    expect(app(TenantResolver::class))->toBeInstanceOf(NullTenantResolver::class);
});

it('falls back when an unknown driver is configured', function (): void {
    config()->set('cairn.driver', 'cassandra');

    expect(app(Ingest::class))->toBeInstanceOf(NullIngest::class);
});

it('does not blow up the host application when config is nonsense', function (): void {
    config()->set('cairn.driver', ['not', 'a', 'string']);

    expect(app(Ingest::class))->toBeInstanceOf(NullIngest::class);
});
