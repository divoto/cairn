<?php

declare(strict_types=1);

namespace Divoto\Cairn\Enums;

/**
 * The broad class of device a request came from.
 *
 * Backed by small integers to keep `cairn_entries` narrow. Cases are
 * deliberately coarse — Cairn reports on device classes, not on models, and
 * will not add a model dimension: it narrows the anonymity set without telling
 * a site owner anything they can act on.
 */
enum DeviceType: int
{
    case Unknown = 0;

    case Desktop = 1;

    case Mobile = 2;

    case Tablet = 3;

    case Television = 4;

    case Console = 5;

    case Wearable = 6;

    /**
     * A human-readable label for dashboards and exports.
     */
    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Unknown',
            self::Desktop => 'Desktop',
            self::Mobile => 'Mobile',
            self::Tablet => 'Tablet',
            self::Television => 'TV',
            self::Console => 'Console',
            self::Wearable => 'Wearable',
        };
    }
}
