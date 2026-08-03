<?php

declare(strict_types=1);

namespace Divoto\Cairn\Enums;

/**
 * The operating system a request came from.
 *
 * `cairn_entries.os` is a smallint lookup, so this enum is integer-backed and
 * its case values are permanent: changing one rewrites the meaning of every
 * historical row. Add new cases with new numbers; never renumber.
 *
 * Version numbers are deliberately not recorded, for the same reason they are
 * not recorded for browsers.
 */
enum OperatingSystem: int
{
    case Unknown = 0;

    case Windows = 1;

    case MacOS = 2;

    case Linux = 3;

    case Android = 4;

    case IOS = 5;

    case ChromeOS = 6;

    case IPadOS = 7;

    case FreeBSD = 8;

    case HarmonyOS = 9;

    /** Anything identifiable but not worth its own case. */
    case Other = 99;

    /**
     * A human-readable label for dashboards and exports.
     */
    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Unknown',
            self::Windows => 'Windows',
            self::MacOS => 'macOS',
            self::Linux => 'Linux',
            self::Android => 'Android',
            self::IOS => 'iOS',
            self::ChromeOS => 'ChromeOS',
            self::IPadOS => 'iPadOS',
            self::FreeBSD => 'FreeBSD',
            self::HarmonyOS => 'HarmonyOS',
            self::Other => 'Other',
        };
    }
}
