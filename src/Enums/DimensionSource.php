<?php

declare(strict_types=1);

namespace Divoto\Cairn\Enums;

/**
 * Which table a dimension's rollup is measured from.
 *
 * Most dimensions are columns on `cairn_entries`, so their rollup carries the
 * entry-derived metrics: pageviews, events, conversions, time on page, the
 * Core Web Vitals. Sessions are counted from `cairn_sessions`, which has no
 * dimension columns at all — which is why "sessions from Singapore" is not a
 * number that was ever measured.
 *
 * Landing and exit pages are the exception, and the reason this enum exists.
 * They *are* columns on the session table, so a rollup grouped by one of them
 * can report sessions, bounces and duration — and therefore a bounce rate per
 * landing page, which is the question people actually ask of this data.
 */
enum DimensionSource: string
{
    case Entries = 'entries';

    case Sessions = 'sessions';
}
