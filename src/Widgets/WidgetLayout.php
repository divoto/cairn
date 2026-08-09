<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets;

/**
 * The shapes a widget can take.
 *
 * Deliberately few. A dashboard with a dozen bespoke visualisations is harder
 * to read than one with four repeated ones, because the reader has to learn
 * each panel before they can use it.
 */
enum WidgetLayout: string
{
    /** Headline numbers with their comparison, above a chart. */
    case Overview = 'overview';

    /** A ranked list with a proportion bar. */
    case Table = 'table';

    /** A single number. */
    case Stat = 'stat';

    /** A ranked list drawn beside a world map. */
    case Map = 'map';

    /** A reverse-chronological list of recent activity. */
    case Feed = 'feed';
}
