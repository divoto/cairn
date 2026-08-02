<?php

declare(strict_types=1);

namespace Divoto\Cairn\Geo;

use Divoto\Cairn\Contracts\GeoResolver;
use Divoto\Cairn\Data\GeoLocation;
use Divoto\Cairn\Enums\GeoPrecision;
use Divoto\Cairn\Privacy\IpAnonymiser;
use GeoIp2\Database\Reader;
use GeoIp2\ProviderInterface;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

/**
 * Resolves a location from a local MaxMind database.
 *
 * **Local, always.** Cairn will not call a geolocation web service per
 * request: that would send a visitor's address — even a masked one — to a
 * third party on every pageview, which is the thing this package exists not to
 * do. The lookup happens against a file on your own disk, and nothing leaves
 * the server.
 *
 * `geoip2/geoip2` is a `suggest`, not a `require`. This class is only ever
 * constructed when a deployer has installed it and pointed
 * `cairn.privacy.geo_resolver` here, so an installation without it carries no
 * dead dependency.
 *
 * The address arriving here has already been masked to a /24 or /48 by
 * {@see IpAnonymiser}, and whatever comes back is reduced
 * to the configured precision before it can reach a column. Both of those
 * happen in EntryFactory, not here — this class only answers the question it
 * is asked.
 */
final class MaxMindGeoResolver implements GeoResolver
{
    private bool $unavailable = false;

    public function __construct(
        private readonly Config $config,
        /**
         * Built lazily and reused. Opening the database is the expensive part, and
         * doing it per request would undo the reason for using a local file.
         */
        private ?ProviderInterface $reader = null
    ) {}

    public function resolve(string $ip): ?GeoLocation
    {
        $reader = $this->reader();

        if (! $reader instanceof ProviderInterface) {
            return null;
        }

        try {
            // Only ask for city detail when it could actually be stored.
            // A country database cannot answer a city query at all, and asking
            // for more than the deployer has configured would mean resolving
            // something Cairn is about to throw away.
            if ($this->precision() === GeoPrecision::Country) {
                $country = $reader->country($ip);

                return new GeoLocation(country: $this->code($country->country->isoCode));
            }

            $city = $reader->city($ip);

            return new GeoLocation(
                country: $this->code($city->country->isoCode),
                region: $this->name($city->mostSpecificSubdivision->name),
                city: $this->name($city->city->name),
            );
        } catch (Throwable) {
            // An address the database does not know is the common case here,
            // not an error worth reporting — private ranges, new allocations,
            // and anything the free databases have not seen. A visitor with no
            // known country is simply a visitor with no known country.
            return null;
        }
    }

    /**
     * Whether the database is present and readable.
     *
     * Used by `cairn:doctor` to tell a deployer that geo is configured but not
     * working, rather than leaving them with a permanently empty Countries
     * panel and no explanation.
     */
    public function isAvailable(): bool
    {
        return $this->reader() instanceof ProviderInterface;
    }

    /**
     * The configured database path, or null when none is set.
     */
    public function databasePath(): ?string
    {
        $path = $this->config->get('cairn.privacy.geo_database');

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * Open the database, once.
     *
     * A missing or corrupt file is reported and then remembered as
     * unavailable, so a broken path costs one log line rather than one per
     * request.
     */
    private function reader(): ?ProviderInterface
    {
        if ($this->reader instanceof ProviderInterface || $this->unavailable) {
            return $this->reader;
        }

        $path = $this->databasePath();

        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            $this->unavailable = true;

            return null;
        }

        try {
            return $this->reader = new Reader($path);
        } catch (Throwable $e) {
            report($e);
            $this->unavailable = true;

            return null;
        }
    }

    private function precision(): GeoPrecision
    {
        $configured = $this->config->get('cairn.privacy.geo_precision');

        return (is_string($configured) ? GeoPrecision::tryFrom($configured) : null) ?? GeoPrecision::Country;
    }

    /**
     * An ISO 3166-1 alpha-2 code, or null.
     */
    private function code(?string $value): ?string
    {
        return is_string($value) && strlen($value) === 2 ? strtoupper($value) : null;
    }

    /**
     * A place name, capped to the width of the column it lands in.
     */
    private function name(?string $value): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, 64) : null;
    }
}
