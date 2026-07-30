<?php

declare(strict_types=1);

namespace Divoto\Cairn\Contracts;

use Divoto\Cairn\Data\GeoLocation;

/**
 * Turns an IP address into a location.
 *
 * This is the one place in Cairn that receives a raw IP address, and it is the
 * boundary the address must not cross. An implementation must not log it,
 * cache it, include it in an exception message, or return it in any form.
 *
 * The returned location is reduced to the configured precision by the caller
 * before it reaches an entry, so a resolver that knows the city is harmless on
 * a country-precision deployment.
 *
 * Implementations should be fast and must never block a response — a resolver
 * making a synchronous HTTP call per request is a bug, not a trade-off.
 */
interface GeoResolver
{
    /**
     * Resolve an IP address to a location, or null if it cannot be resolved.
     */
    public function resolve(string $ip): ?GeoLocation;
}
