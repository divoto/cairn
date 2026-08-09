/**
 * Page props for Cairn's Inertia dashboard.
 *
 * These match what Divoto\Cairn\Integrations\Inertia\DashboardController
 * renders. A test asserts the two stay in step.
 *
 * No page component is published alongside this file. The one you write against
 * these types is yours: you own it, and Cairn does not maintain its appearance.
 */

export type MetricUnit =
    | 'count'
    | 'decimal'
    | 'percentage'
    | 'seconds'
    | 'milliseconds'
    | 'currency';

export type WidgetLayout = 'overview' | 'table' | 'stat' | 'map' | 'feed';

export interface MetricDefinition {
    key: string;
    label: string;
    unit: MetricUnit;
}

export interface ReportRow {
    bucket?: string;
    dimensions?: Record<string, string | number | null>;
    metrics?: Record<string, number>;
    previous?: Record<string, number>;
    /** True when a figure is an estimate — see the privacy model. */
    approximate?: boolean;
}

export interface Widget {
    key: string;
    title: string;
    description: string | null;
    layout: WidgetLayout;
    /** True when the panel wants the full width of the grid row. */
    wide: boolean;
    dimension: string | null;
    metrics: MetricDefinition[];
    /** Shown instead of the rows when there are none. */
    empty: string;
    rows: ReportRow[];
}

export interface Filters {
    range: string;
    rangeLabel: string;
    comparison: 'none' | 'previous_period' | 'previous_year';
    interval: 'hour' | 'day' | 'month';
    from: string;
    to: string;
    active: Record<string, string>;
}

export interface DashboardProps {
    filters: Filters;
    ranges: Record<string, string>;
    widgets: Widget[];
}
