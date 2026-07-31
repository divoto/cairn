<?php

declare(strict_types=1);

namespace Divoto\Cairn\Enums;

/**
 * Something a report can be grouped or filtered by.
 *
 * Every dimension maps to a column on `cairn_entries`, but not every dimension
 * is materialised into `cairn_aggregates` — the cross-product of dimensions
 * explodes, so v1 ships a deliberately small set of single-dimension rollups.
 *
 * A report requesting a combination that was never materialised throws rather
 * than silently falling back to scanning raw entries. The dashboard must never
 * scan `cairn_entries`, and a quiet fallback is exactly how that rule would be
 * broken without anyone noticing.
 */
enum Dimension: string
{
    case Route = 'route';

    case Url = 'url';

    case ReferrerHost = 'referrer_host';

    case Channel = 'channel';

    case UtmSource = 'utm_source';

    case UtmMedium = 'utm_medium';

    case UtmCampaign = 'utm_campaign';

    case UtmTerm = 'utm_term';

    case UtmContent = 'utm_content';

    case Country = 'country';

    case Region = 'region';

    case City = 'city';

    case DeviceType = 'device_type';

    case Browser = 'browser';

    case OperatingSystem = 'os';

    case ScreenClass = 'screen_class';

    case Language = 'language';

    /** The `name` column, when the entry is an event or a conversion. */
    case EventName = 'name';

    /**
     * The column on `cairn_entries` this dimension reads from.
     */
    public function column(): string
    {
        return $this->value;
    }

    /**
     * Whether v1 materialises a single-dimension rollup for this dimension.
     *
     * The set is intentionally conservative. Adding a dimension here means
     * every rollup writes more rows for every bucket, forever.
     */
    public function isMaterialised(): bool
    {
        return match ($this) {
            self::Route,
            self::ReferrerHost,
            self::Channel,
            self::UtmSource,
            self::UtmMedium,
            self::UtmCampaign,
            self::UtmTerm,
            self::UtmContent,
            self::Country,
            self::DeviceType,
            self::Browser,
            self::OperatingSystem,
            self::EventName => true,
            default => false,
        };
    }

    /**
     * Whether values of this dimension are only present with the JS beacon.
     */
    public function requiresBeacon(): bool
    {
        return $this === self::ScreenClass;
    }

    /**
     * The minimum `privacy.geo_precision` setting this dimension requires.
     *
     * Returns null for dimensions unrelated to geography. Region and city are
     * withheld unless the deployer has explicitly widened geo precision, and
     * `cairn:doctor` reports when they have.
     */
    public function requiredGeoPrecision(): ?GeoPrecision
    {
        return match ($this) {
            self::Country => GeoPrecision::Country,
            self::Region => GeoPrecision::Region,
            self::City => GeoPrecision::City,
            default => null,
        };
    }

    /**
     * Turn a stored dimension value into something readable.
     *
     * Channel, device type, browser and operating system are stored as small
     * integers — the column is written on every pageview, and the enum case
     * values are permanent. Rendering the stored value would put "3" on the
     * dashboard where "Social" belongs.
     */
    public function display(string|int|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $numeric = is_numeric($value) ? (int) $value : null;

        if ($numeric !== null) {
            $label = match ($this) {
                self::Channel => Channel::tryFrom($numeric)?->label(),
                self::DeviceType => DeviceType::tryFrom($numeric)?->label(),
                self::Browser => Browser::tryFrom($numeric)?->label(),
                self::OperatingSystem => OperatingSystem::tryFrom($numeric)?->label(),
                self::ScreenClass => ScreenClass::tryFrom($numeric)?->label(),
                default => null,
            };

            if ($label !== null) {
                return $label;
            }
        }

        return (string) $value;
    }

    /**
     * A human-readable label for dashboards and exports.
     */
    public function label(): string
    {
        return match ($this) {
            self::Route => 'Route',
            self::Url => 'Page',
            self::ReferrerHost => 'Referrer',
            self::Channel => 'Channel',
            self::UtmSource => 'UTM source',
            self::UtmMedium => 'UTM medium',
            self::UtmCampaign => 'UTM campaign',
            self::UtmTerm => 'UTM term',
            self::UtmContent => 'UTM content',
            self::Country => 'Country',
            self::Region => 'Region',
            self::City => 'City',
            self::DeviceType => 'Device',
            self::Browser => 'Browser',
            self::OperatingSystem => 'Operating system',
            self::ScreenClass => 'Screen size',
            self::Language => 'Language',
            self::EventName => 'Event',
        };
    }
}
