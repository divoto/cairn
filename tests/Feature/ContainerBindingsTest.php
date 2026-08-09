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
use Divoto\Cairn\Counting\DatabaseUniqueCounter;
use Divoto\Cairn\Counting\RedisUniqueCounter;
use Divoto\Cairn\Ingest\DatabaseIngest;
use Divoto\Cairn\Ingest\NullIngest;
use Divoto\Cairn\Ingest\RedisIngest;
use Divoto\Cairn\Presence\DatabasePresence;
use Divoto\Cairn\Presence\RedisPresence;
use Divoto\Cairn\Storage\DatabaseStorage;
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

it('resolves the database drivers by default', function (): void {
    expect(app(Ingest::class))->toBeInstanceOf(DatabaseIngest::class)
        ->and(app(Storage::class))->toBeInstanceOf(DatabaseStorage::class)
        ->and(app(UniqueCounter::class))->toBeInstanceOf(DatabaseUniqueCounter::class)
        ->and(app(Presence::class))->toBeInstanceOf(DatabasePresence::class);
});

it('resolves the Redis drivers when configured', function (): void {
    config()->set('cairn.driver', 'redis');

    expect(app(Ingest::class))->toBeInstanceOf(RedisIngest::class)
        ->and(app(UniqueCounter::class))->toBeInstanceOf(RedisUniqueCounter::class)
        ->and(app(Presence::class))->toBeInstanceOf(RedisPresence::class);
});

/**
 * `cairn.driver` selects where entries are buffered, counted and tracked —
 * not where they are durably stored. There is one storage driver in v1, and
 * choosing Redis must not silently change what the permanent record is.
 */
it('keeps storage on the database whichever driver is chosen', function (): void {
    config()->set('cairn.driver', 'redis');

    expect(app(Storage::class))->toBeInstanceOf(DatabaseStorage::class);
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
