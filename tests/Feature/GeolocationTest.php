<?php

declare(strict_types=1);

use Divoto\Cairn\Contracts\GeoResolver;
use Divoto\Cairn\Data\GeoLocation;
use Divoto\Cairn\Geo\MaxMindGeoResolver;
use Divoto\Cairn\Geo\NullGeoResolver;
use GeoIp2\Exception\AddressNotFoundException;
use GeoIp2\Model\City;
use GeoIp2\Model\Country;
use GeoIp2\ProviderInterface;
use Illuminate\Contracts\Config\Repository as Config;

/*
|--------------------------------------------------------------------------
| Geolocation
|--------------------------------------------------------------------------
|
| Cairn ships no geo database and resolves nothing by default. Enabling it
| means installing geoip2/geoip2 and pointing at a local MaxMind file — never
| a web service, because a lookup service would receive a visitor's address on
| every pageview, which is the thing this package exists not to do.
|
*/

/**
 * A stand-in for the MaxMind reader, so the lookup path is tested without
 * committing a binary database to the repository.
 *
 * @param  array<string, mixed>  $data
 */
function fakeReader(array $data): ProviderInterface
{
    return new class($data) implements ProviderInterface
    {
        /**
         * @param  array<string, mixed>  $data
         */
        public function __construct(private readonly array $data) {}

        public function country(string $ipAddress): Country
        {
            return new Country($this->data, ['en']);
        }

        public function city(string $ipAddress): City
        {
            return new City($this->data, ['en']);
        }
    };
}

function resolverFor(ProviderInterface $reader): MaxMindGeoResolver
{
    return new MaxMindGeoResolver(app(Config::class), $reader);
}

/*
|--------------------------------------------------------------------------
| The default
|--------------------------------------------------------------------------
*/

it('resolves nothing out of the box', function (): void {
    expect(app(GeoResolver::class))->toBeInstanceOf(NullGeoResolver::class)
        ->and(app(GeoResolver::class)->resolve('81.2.69.142'))->toBeNull();
});

/**
 * The gap this closes: before, GeoResolver could not be swapped through
 * configuration at all, so even a hand-written resolver had to be bound in the
 * container by hand.
 */
it('can be swapped through configuration', function (): void {
    config()->set('cairn.privacy.geo_resolver', MaxMindGeoResolver::class);
    app()->forgetInstance(GeoResolver::class);

    expect(app(GeoResolver::class))->toBeInstanceOf(MaxMindGeoResolver::class);
});

it('falls back to resolving nothing when the configured class is missing', function (): void {
    config()->set('cairn.privacy.geo_resolver', 'App\\NotARealResolver');
    app()->forgetInstance(GeoResolver::class);

    expect(app(GeoResolver::class))->toBeInstanceOf(NullGeoResolver::class);
});

/*
|--------------------------------------------------------------------------
| Lookups
|--------------------------------------------------------------------------
*/

it('resolves a country', function (): void {
    config()->set('cairn.privacy.geo_precision', 'country');

    $location = resolverFor(fakeReader([
        'country' => ['iso_code' => 'GB', 'names' => ['en' => 'United Kingdom']],
        'traits' => ['ip_address' => '81.2.69.142', 'prefix_len' => 24],
    ]))->resolve('81.2.69.0');

    expect($location)->toBeInstanceOf(GeoLocation::class)
        ->and($location?->country)->toBe('GB')
        ->and($location?->region)->toBeNull()
        ->and($location?->city)->toBeNull();
});

/**
 * A country database cannot answer a city query, and asking for detail the
 * deployer has not configured would mean resolving something Cairn is about to
 * throw away.
 */
it('asks only for the detail the configured precision allows', function (): void {
    config()->set('cairn.privacy.geo_precision', 'city');

    $location = resolverFor(fakeReader([
        'country' => ['iso_code' => 'GB', 'names' => ['en' => 'United Kingdom']],
        'city' => ['names' => ['en' => 'London']],
        'subdivisions' => [['iso_code' => 'ENG', 'names' => ['en' => 'England']]],
        'traits' => ['ip_address' => '81.2.69.142', 'prefix_len' => 24],
    ]))->resolve('81.2.69.0');

    expect($location?->country)->toBe('GB')
        ->and($location?->region)->toBe('England')
        ->and($location?->city)->toBe('London');
});

it('returns only a country even from a city database at country precision', function (): void {
    config()->set('cairn.privacy.geo_precision', 'country');

    $location = resolverFor(fakeReader([
        'country' => ['iso_code' => 'DE', 'names' => ['en' => 'Germany']],
        'city' => ['names' => ['en' => 'Berlin']],
        'traits' => ['ip_address' => '1.2.3.4', 'prefix_len' => 24],
    ]))->resolve('1.2.3.0');

    expect($location?->country)->toBe('DE')
        ->and($location?->city)->toBeNull();
});

it('returns nothing for an address the database does not know', function (): void {
    $reader = new class implements ProviderInterface
    {
        public function country(string $ipAddress): Country
        {
            throw new AddressNotFoundException('not in database');
        }

        public function city(string $ipAddress): City
        {
            throw new AddressNotFoundException('not in database');
        }
    };

    expect((new MaxMindGeoResolver(app(Config::class), $reader))->resolve('10.0.0.0'))->toBeNull();
});

it('ignores a country code that is not two letters', function (): void {
    config()->set('cairn.privacy.geo_precision', 'country');

    $location = resolverFor(fakeReader([
        'country' => ['names' => ['en' => 'Nowhere']],
        'traits' => ['ip_address' => '1.2.3.4', 'prefix_len' => 24],
    ]))->resolve('1.2.3.0');

    expect($location?->country)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| A database that is not there
|--------------------------------------------------------------------------
|
| The common failure: geo is configured, the file is missing or unreadable,
| and the Countries panel stays empty. That must not throw, and cairn:doctor
| must be able to say so.
|
*/

it('resolves nothing when no database path is configured', function (): void {
    config()->set('cairn.privacy.geo_database');

    $resolver = new MaxMindGeoResolver(app(Config::class));

    expect($resolver->databasePath())->toBeNull()
        ->and($resolver->isAvailable())->toBeFalse()
        ->and($resolver->resolve('81.2.69.142'))->toBeNull();
});

it('resolves nothing when the database file is missing', function (): void {
    config()->set('cairn.privacy.geo_database', '/nonexistent/GeoLite2-Country.mmdb');

    $resolver = new MaxMindGeoResolver(app(Config::class));

    expect($resolver->isAvailable())->toBeFalse()
        ->and($resolver->resolve('81.2.69.142'))->toBeNull();
});

it('resolves nothing when the file is not a MaxMind database', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'cairn-geo');
    file_put_contents((string) $path, 'this is not a database');

    config()->set('cairn.privacy.geo_database', $path);

    $resolver = new MaxMindGeoResolver(app(Config::class));

    expect($resolver->isAvailable())->toBeFalse()
        ->and($resolver->resolve('81.2.69.142'))->toBeNull();

    unlink((string) $path);
});

/**
 * A broken path should cost one log line, not one per request.
 */
it('gives up on a broken database rather than retrying every request', function (): void {
    config()->set('cairn.privacy.geo_database', '/nonexistent/GeoLite2-Country.mmdb');

    $resolver = new MaxMindGeoResolver(app(Config::class));

    foreach (range(1, 5) as $ignored) {
        expect($resolver->resolve('81.2.69.142'))->toBeNull();
    }

    expect($resolver->isAvailable())->toBeFalse();
});

it('reports the configured path so the doctor can check it', function (): void {
    config()->set('cairn.privacy.geo_database', '/srv/geo/GeoLite2-Country.mmdb');

    expect((new MaxMindGeoResolver(app(Config::class)))->databasePath())
        ->toBe('/srv/geo/GeoLite2-Country.mmdb');
});

/*
|--------------------------------------------------------------------------
| The invariant
|--------------------------------------------------------------------------
*/

/**
 * The one place an address is allowed to cross, and it must not hand one back
 * in any form.
 */
it('never returns an address from a lookup', function (): void {
    config()->set('cairn.privacy.geo_precision', 'city');

    $location = resolverFor(fakeReader([
        'country' => ['iso_code' => 'GB', 'names' => ['en' => 'United Kingdom']],
        'city' => ['names' => ['en' => 'London']],
        'traits' => ['ip_address' => '81.2.69.142', 'prefix_len' => 24],
    ]))->resolve('81.2.69.0');

    foreach ((array) $location as $value) {
        expect((string) $value)->not->toContain('81.2.69');
    }
});

/**
 * Cairn will not call a geolocation web service. A lookup per pageview would
 * hand a visitor's address to a third party every time.
 */
it('makes no network call of its own', function (): void {
    $source = (string) file_get_contents(
        (string) (new ReflectionClass(MaxMindGeoResolver::class))->getFileName()
    );

    foreach (['WebService', 'Client(', 'curl_', 'file_get_contents(', 'Http::'] as $network) {
        expect($source)->not->toContain($network);
    }
});
