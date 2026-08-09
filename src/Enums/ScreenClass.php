<?php

declare(strict_types=1);

namespace Divoto\Cairn\Enums;

/**
 * A coarse bucket for viewport width, reported by the optional JS beacon.
 *
 * Exact screen dimensions are a strong fingerprinting signal, so Cairn buckets
 * them on arrival and stores only the bucket. The raw width never reaches a
 * column.
 *
 * With the beacon disabled this is always null on an entry, and widgets that
 * depend on it render an empty state rather than breaking.
 */
enum ScreenClass: int
{
    case Unknown = 0;

    /** Below 640 CSS pixels. */
    case Small = 1;

    /** 640 to 1023 CSS pixels. */
    case Medium = 2;

    /** 1024 to 1439 CSS pixels. */
    case Large = 3;

    /** 1440 CSS pixels and above. */
    case ExtraLarge = 4;

    /**
     * Bucket a viewport width, discarding the exact value.
     */
    public static function fromWidth(int $width): self
    {
        return match (true) {
            $width <= 0 => self::Unknown,
            $width < 640 => self::Small,
            $width < 1024 => self::Medium,
            $width < 1440 => self::Large,
            default => self::ExtraLarge,
        };
    }

    /**
     * A human-readable label for dashboards and exports.
     */
    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Unknown',
            self::Small => 'Small (< 640px)',
            self::Medium => 'Medium (640–1023px)',
            self::Large => 'Large (1024–1439px)',
            self::ExtraLarge => 'Extra large (≥ 1440px)',
        };
    }
}
