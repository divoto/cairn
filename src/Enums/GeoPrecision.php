<?php

declare(strict_types=1);

namespace Divoto\Cairn\Enums;

/**
 * How precisely a resolved location may be stored.
 *
 * Country is the default and is what Cairn recommends. Each step beyond it
 * narrows the anonymity set of a visitor hash: a country plus a browser plus a
 * device class describes a very large group of people, while a city plus the
 * same attributes may describe a handful.
 *
 * This setting governs storage, not lookup. Whatever the resolver returns,
 * anything finer than the configured precision is discarded before an entry is
 * built — it never reaches a column.
 */
enum GeoPrecision: string
{
    /** Store no location at all. */
    case None = 'none';

    /** Store the ISO 3166-1 alpha-2 country code only. */
    case Country = 'country';

    /** Store country and region. */
    case Region = 'region';

    /** Store country, region and city. */
    case City = 'city';

    /**
     * Whether this precision permits storing the given level of detail.
     */
    public function allows(self $level): bool
    {
        return $this->rank() >= $level->rank();
    }

    /**
     * Whether choosing this precision meaningfully increases re-identification
     * risk, and therefore warrants a `cairn:doctor` finding.
     */
    public function isElevated(): bool
    {
        return $this === self::Region || $this === self::City;
    }

    /**
     * Ordering used by {@see self::allows()}.
     */
    private function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::Country => 1,
            self::Region => 2,
            self::City => 3,
        };
    }
}
