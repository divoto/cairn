<?php

declare(strict_types=1);

namespace Divoto\Cairn\Geo;

use Divoto\Cairn\Contracts\GeoResolver;
use Divoto\Cairn\Data\GeoLocation;

/**
 * A geo resolver that resolves nothing.
 *
 * This is the shipped default. Cairn does not bundle a geo database and will
 * not make an HTTP call per request to a third party — that would send visitor
 * IP addresses off the server, which is the thing this package exists not to
 * do. A deployer who wants country reporting installs `geoip2/geoip2` with a
 * local MaxMind database and configures a resolver.
 *
 * The IP passed here is discarded immediately and is never stored, logged or
 * echoed back.
 */
final class NullGeoResolver implements GeoResolver
{
    public function resolve(string $ip): ?GeoLocation
    {
        return null;
    }
}
