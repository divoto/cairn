<?php

declare(strict_types=1);

namespace Divoto\Cairn\Enums;

/**
 * The browser a request came from.
 *
 * `cairn_entries.browser` is a smallint lookup, so this enum is integer-backed
 * and its case values are permanent: changing one rewrites the meaning of
 * every historical row. Add new cases with new numbers; never renumber.
 *
 * Version numbers are deliberately not recorded. They add cardinality, narrow
 * the anonymity set, and answer no question a site owner actually has.
 */
enum Browser: int
{
    case Unknown = 0;

    case Chrome = 1;

    case Safari = 2;

    case Firefox = 3;

    case Edge = 4;

    case Opera = 5;

    case SamsungInternet = 6;

    case InternetExplorer = 7;

    case Brave = 8;

    case Vivaldi = 9;

    case DuckDuckGo = 10;

    case Yandex = 11;

    case UcBrowser = 12;

    /** Anything identifiable but not worth its own case. */
    case Other = 99;

    /**
     * A human-readable label for dashboards and exports.
     */
    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Unknown',
            self::Chrome => 'Chrome',
            self::Safari => 'Safari',
            self::Firefox => 'Firefox',
            self::Edge => 'Edge',
            self::Opera => 'Opera',
            self::SamsungInternet => 'Samsung Internet',
            self::InternetExplorer => 'Internet Explorer',
            self::Brave => 'Brave',
            self::Vivaldi => 'Vivaldi',
            self::DuckDuckGo => 'DuckDuckGo',
            self::Yandex => 'Yandex',
            self::UcBrowser => 'UC Browser',
            self::Other => 'Other',
        };
    }
}
