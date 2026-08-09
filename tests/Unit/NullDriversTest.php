<?php

declare(strict_types=1);

use Divoto\Cairn\Consent\GrantingConsentResolver;
use Divoto\Cairn\Contracts\GeoResolver;
use Divoto\Cairn\Contracts\Ingest;
use Divoto\Cairn\Contracts\Presence;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Contracts\UniqueCounter;
use Divoto\Cairn\Counting\NullUniqueCounter;
use Divoto\Cairn\Data\AggregateQuery;
use Divoto\Cairn\Data\ClientHints;
use Divoto\Cairn\Data\Entry;
use Divoto\Cairn\Detection\NullBotDetector;
use Divoto\Cairn\Detection\NullDeviceDetector;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Enums\Period;
use Divoto\Cairn\Geo\NullGeoResolver;
use Divoto\Cairn\Ingest\NullIngest;
use Divoto\Cairn\Presence\NullPresence;
use Divoto\Cairn\Recorders\ClientMetrics;
use Divoto\Cairn\Recorders\Conversions;
use Divoto\Cairn\Recorders\PageViews;
use Divoto\Cairn\Recorders\Recorder;
use Divoto\Cairn\Storage\NullStorage;
use Divoto\Cairn\Tenancy\NullTenantResolver;
use Illuminate\Support\Collection;

/*
|--------------------------------------------------------------------------
| The no-op drivers
|--------------------------------------------------------------------------
|
| These are what every contract resolves to when `cairn.enabled` is false, so
| a disabled installation cannot record, count or store anything even if some
| code path tries. Doing nothing is their entire job, and it is worth proving
| they do it without throwing — a null driver that raised would turn a
| deliberately silent installation into a broken one.
|
*/

it('discards everything given to the null ingest', function (): void {
    $ingest = new NullIngest;

    $ingest->record(anEntry());
    $ingest->record(anEntry());
    $ingest->trim();

    expect($ingest->digest(new NullStorage))->toBe(0);
});

it('persists nothing and reports nothing from the null storage', function (): void {
    $storage = new NullStorage;

    /** @var Collection<int, Entry> $entries */
    $entries = new Collection([anEntry(), anEntry()]);

    $storage->store($entries);

    $query = new AggregateQuery(
        from: now()->toImmutable(),
        to: now()->addDay()->toImmutable(),
        period: Period::Day,
        metrics: [Metric::Pageviews],
    );

    expect($storage->aggregate($query))->toBeEmpty()
        ->and($storage->rollup(now(), now()->addDay(), Period::Day))->toBe(0)
        ->and($storage->prune(now()))->toBe(0);
});

it('counts nothing in the null unique counter', function (): void {
    $counter = new NullUniqueCounter;

    $counter->add('2026-03-14', 'overall', random_bytes(16));

    expect($counter->count('2026-03-14', 'overall'))->toBe(0)
        ->and($counter->prune('2026-03-14'))->toBe(0);
});

it('sees nobody in the null presence driver', function (): void {
    $presence = new NullPresence;

    $presence->touch(random_bytes(16), '/pricing');

    expect($presence->count())->toBe(0)
        ->and($presence->recent())->toBeEmpty()
        ->and($presence->recent(10))->toBeEmpty();
});

/**
 * The shipped default. Cairn bundles no geo database and will not make an HTTP
 * call to a third party per request — that would send visitor addresses off the
 * server, which is the thing this package exists not to do.
 */
it('resolves no location by default', function (): void {
    expect((new NullGeoResolver)->resolve('203.0.113.7'))->toBeNull();
});

it('classifies nothing as a bot in the null detector', function (): void {
    $detector = new NullBotDetector;

    expect($detector->isBot('Googlebot/2.1'))->toBeFalse()
        ->and($detector->isBot(null))->toBeFalse();
});

it('identifies no device in the null detector', function (): void {
    $device = (new NullDeviceDetector)->detect('Mozilla/5.0', ClientHints::none());

    expect($device->isKnown())->toBeFalse();
});

it('resolves no tenant by default', function (): void {
    expect((new NullTenantResolver)->resolve())->toBeNull();
});

/**
 * Named for what it does rather than "Null", because "null" would leave it
 * ambiguous whether the default permits or refuses.
 */
it('grants consent by default', function (): void {
    expect((new GrantingConsentResolver)->granted(request()))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Recorders
|--------------------------------------------------------------------------
*/

it('keys each recorder by its own class name', function (Recorder $recorder, string $class): void {
    expect($recorder->key())->toBe($class);
})->with([
    'pageviews' => [new PageViews, PageViews::class],
    'client metrics' => [new ClientMetrics, ClientMetrics::class],
    'conversions' => [new Conversions, Conversions::class],
]);

/*
|--------------------------------------------------------------------------
| Container wiring
|--------------------------------------------------------------------------
*/

it('binds every no-op driver when Cairn is disabled', function (): void {
    config()->set('cairn.enabled', false);

    expect(app(Ingest::class))->toBeInstanceOf(NullIngest::class)
        ->and(app(Storage::class))->toBeInstanceOf(NullStorage::class)
        ->and(app(UniqueCounter::class))->toBeInstanceOf(NullUniqueCounter::class)
        ->and(app(Presence::class))->toBeInstanceOf(NullPresence::class)
        ->and(app(GeoResolver::class))->toBeInstanceOf(NullGeoResolver::class);
});
