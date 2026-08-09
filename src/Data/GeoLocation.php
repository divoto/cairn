<?php

declare(strict_types=1);

namespace Divoto\Cairn\Data;

use Divoto\Cairn\Enums\GeoPrecision;

/**
 * A resolved location, already reduced to the configured precision.
 *
 * This is what travels onward from a geo lookup. The IP address that produced
 * it does not: it goes out of scope at the resolver boundary and must never
 * appear on this object, in a log line, or in an exception message.
 */
final readonly class GeoLocation
{
    /**
     * @param  string|null  $country  ISO 3166-1 alpha-2, uppercase.
     */
    public function __construct(
        public ?string $country = null,
        public ?string $region = null,
        public ?string $city = null,
    ) {}

    /**
     * An empty location, for when a lookup found nothing.
     */
    public static function unknown(): self
    {
        return new self;
    }

    /**
     * Return a copy reduced to the given precision.
     *
     * Called before an entry is built, so that a resolver returning city-level
     * detail on a country-precision deployment cannot leak it into a column.
     */
    public function reduceTo(GeoPrecision $precision): self
    {
        return match ($precision) {
            GeoPrecision::None => self::unknown(),
            GeoPrecision::Country => new self($this->country),
            GeoPrecision::Region => new self($this->country, $this->region),
            GeoPrecision::City => $this,
        };
    }

    /**
     * Whether the lookup produced anything at all.
     */
    public function isKnown(): bool
    {
        return $this->country !== null;
    }
}
