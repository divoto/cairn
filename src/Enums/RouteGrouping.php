<?php

declare(strict_types=1);

namespace Divoto\Cairn\Enums;

/**
 * How a request becomes a row in the routes table.
 *
 * The default answers "what page is this?" with the route name, which is the
 * thing an external analytics tag cannot know. That is the right answer for an
 * application whose routes are mostly distinct pages.
 *
 * It is the wrong answer for an application that serves many pages from one
 * parameterised route — a CMS on `/{page}`, a docs site on `/docs/{slug}`. There
 * the route name is the same string for every page, and the whole site collapses
 * into a single row. Those deployments want `path`.
 */
enum RouteGrouping: string
{
    /**
     * The route name, falling back to the URI pattern, then the collapsed path.
     */
    case Name = 'name';

    /**
     * The route's URI pattern — `/orders/{order}` — ignoring its name.
     *
     * Useful when route names are inconsistent or absent but the patterns are
     * meaningful, and when you would rather read URLs than names.
     */
    case Uri = 'uri';

    /**
     * The requested path, with identifier-looking segments collapsed to `{id}`.
     *
     * Distinguishes pages that share a route. Numeric ids, UUIDs and ULIDs are
     * still collapsed, so `/orders/8814` does not fragment — but a slug is a
     * name, not an identifier, and is kept.
     */
    case Path = 'path';

    /**
     * Read the setting, treating anything unrecognised as the default.
     *
     * A typo in a config file should not stop pageviews being recorded, so this
     * never throws — `cairn:doctor` is where a deployer learns what is in force.
     */
    public static function fromConfig(mixed $value): self
    {
        return (is_string($value) ? self::tryFrom($value) : null) ?? self::Name;
    }

    /**
     * Whether this grouping puts no bound on how many distinct rows exist.
     *
     * Route names and URI patterns are bounded by the route table. Paths are
     * bounded only by what visitors ask for, which is why `cairn:doctor`
     * mentions it.
     */
    public function isUnbounded(): bool
    {
        return $this === self::Path;
    }

    /**
     * What the routes table is showing, in one sentence.
     */
    public function description(): string
    {
        return match ($this) {
            self::Name => 'Grouped by route name, so /orders/8814/invoice and /orders/9921/invoice count as one page rather than two.',
            self::Uri => 'Grouped by route pattern, so /orders/8814/invoice and /orders/9921/invoice both count as /orders/{order}/invoice.',
            self::Path => 'Grouped by URL path, so pages sharing a route are counted separately. Numeric ids, UUIDs and ULIDs still collapse to {id}.',
        };
    }
}
