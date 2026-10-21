<?php

declare(strict_types=1);

namespace Divoto\Cairn\Enums;

/**
 * What kind of thing a raw entry records.
 *
 * Stored as a string in `cairn_entries.type` rather than an integer: the
 * cardinality is tiny, the column is cheap, and a human reading the table
 * directly should not need a lookup to understand it.
 */
enum EntryType: string
{
    /** A page was viewed. Recorded server-side by the PageViews recorder. */
    case Pageview = 'pageview';

    /** A named event occurred, optionally carrying custom properties. */
    case Event = 'event';

    /** A named conversion occurred, optionally carrying a monetary value. */
    case Conversion = 'conversion';

    /**
     * A request matched no route and was answered 404.
     *
     * Not a pageview: nothing was viewed, and a scanner probing for
     * `/wp-login.php` is not traffic. Entries of this type never count as
     * pageviews, sessions or visitors. They exist so a dead link has a row
     * in Broken pages, which no matched route could ever give it.
     */
    case NotFound = 'not_found';

    /**
     * Whether entries of this type may carry a monetary value.
     */
    public function supportsValue(): bool
    {
        return $this === self::Conversion;
    }

    /**
     * Whether entries of this type require a name.
     *
     * A pageview is identified by its route and URL, and a request that
     * matched nothing by its URL; an event or conversion is meaningless
     * without a name to group it by.
     */
    public function requiresName(): bool
    {
        return $this === self::Event || $this === self::Conversion;
    }
}
