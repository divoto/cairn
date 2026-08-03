<?php

declare(strict_types=1);

namespace Divoto\Cairn\Enums;

/**
 * The granularity of an aggregate bucket.
 *
 * Buckets are always calendar-aligned and never trimmed: an hour bucket starts
 * on the hour, a day bucket at midnight, a month bucket on the first of the
 * month. A "last 30 days" window is therefore 30 whole day buckets, not a
 * rolling window sliced at the current minute.
 */
enum Period: string
{
    case Hour = 'hour';

    case Day = 'day';

    case Month = 'month';

    /**
     * The coarser period this one rolls up into, if any.
     *
     * Month is the coarsest period Cairn materialises.
     */
    public function parent(): ?self
    {
        return match ($this) {
            self::Hour => self::Day,
            self::Day => self::Month,
            self::Month => null,
        };
    }

    /**
     * Whether buckets of this period have a fixed length in seconds.
     *
     * Only Hour does. Days vary across daylight-saving transitions and months
     * vary by definition, so neither may be computed by multiplication —
     * always align them through a calendar-aware date library.
     */
    public function isFixedLength(): bool
    {
        return $this === self::Hour;
    }
}
