<?php

declare(strict_types=1);

namespace Divoto\Cairn\Enums;

/**
 * A number the report builder can produce.
 *
 * Metrics are either **additive** — they may be summed across buckets and
 * across dimension values — or **derived**, meaning they are a ratio of two
 * additive metrics.
 *
 * This distinction is the whole point of the enum. A derived metric must be
 * recomputed from its stored components at the level it is displayed at, never
 * averaged from per-bucket ratios. Averaging a bounce rate across seven daily
 * bounce rates gives a different, wrong answer than dividing the week's bounces
 * by the week's sessions, and the error grows as traffic varies between days.
 *
 * Only additive metrics are stored in `cairn_aggregates`. Derived metrics are
 * computed on read via {@see self::ratio()}.
 */
enum Metric: string
{
    // ---------------------------------------------------------------------
    // Additive metrics — stored in cairn_aggregates, safe to sum.
    // ---------------------------------------------------------------------

    case Pageviews = 'pageviews';

    case Sessions = 'sessions';

    case Bounces = 'bounces';

    case Events = 'events';

    case Conversions = 'conversions';

    case ConversionValue = 'conversion_value';

    /** Total session length in seconds; the numerator of AvgSessionDuration. */
    case SessionSeconds = 'session_seconds';

    /** Total measured time on page; only non-zero when the beacon is enabled. */
    case TimeOnPageSeconds = 'time_on_page_seconds';

    /** How many entries contributed to TimeOnPageSeconds. */
    case TimeOnPageSamples = 'time_on_page_samples';

    /** Sum of scroll depth percentages; only non-zero with the beacon enabled. */
    case ScrollDepthTotal = 'scroll_depth_total';

    /** How many entries contributed to ScrollDepthTotal. */
    case ScrollDepthSamples = 'scroll_depth_samples';

    /** Total server response time in milliseconds. */
    case ResponseMilliseconds = 'response_milliseconds';

    /** How many entries contributed to ResponseMilliseconds. */
    case ResponseSamples = 'response_samples';

    /**
     * Unique visitors.
     *
     * Additive in the sense that the report builder sums it, but it comes from
     * the UniqueCounter rather than from `cairn_aggregates`, and summing daily
     * uniques over a multi-day range counts a returning visitor once per day.
     * That is a deliberate consequence of rotating the salt every 24 hours:
     * cross-day identity does not exist to deduplicate against. Rows carrying
     * this metric over a range longer than a day are flagged approximate.
     */
    case Visitors = 'visitors';

    // ---------------------------------------------------------------------
    // Derived metrics — never stored, always recomputed from components.
    // ---------------------------------------------------------------------

    case BounceRate = 'bounce_rate';

    case AvgSessionDuration = 'avg_session_duration';

    case AvgTimeOnPage = 'avg_time_on_page';

    case AvgScrollDepth = 'avg_scroll_depth';

    case AvgResponseTime = 'avg_response_time';

    case ViewsPerSession = 'views_per_session';

    case ConversionRate = 'conversion_rate';

    /**
     * Whether this metric may be summed directly across buckets.
     */
    public function isAdditive(): bool
    {
        return $this->ratio() === null;
    }

    /**
     * The additive numerator and denominator a derived metric is computed from.
     *
     * Returns null for additive metrics. Callers must guard against a zero
     * denominator; there is no meaningful bounce rate for zero sessions, and
     * reporting 0% would be a claim the data does not support.
     *
     * @return array{numerator: self, denominator: self}|null
     */
    public function ratio(): ?array
    {
        return match ($this) {
            self::BounceRate => ['numerator' => self::Bounces, 'denominator' => self::Sessions],
            self::AvgSessionDuration => ['numerator' => self::SessionSeconds, 'denominator' => self::Sessions],
            self::AvgTimeOnPage => ['numerator' => self::TimeOnPageSeconds, 'denominator' => self::TimeOnPageSamples],
            self::AvgScrollDepth => ['numerator' => self::ScrollDepthTotal, 'denominator' => self::ScrollDepthSamples],
            self::AvgResponseTime => ['numerator' => self::ResponseMilliseconds, 'denominator' => self::ResponseSamples],
            self::ViewsPerSession => ['numerator' => self::Pageviews, 'denominator' => self::Sessions],
            self::ConversionRate => ['numerator' => self::Conversions, 'denominator' => self::Sessions],
            default => null,
        };
    }

    /**
     * Whether this metric is stored in `cairn_aggregates`.
     *
     * Visitors is the exception among additive metrics: it lives in the
     * UniqueCounter, because a set cardinality cannot be summed out of a
     * rollup table.
     */
    public function isStored(): bool
    {
        return $this->isAdditive() && $this !== self::Visitors;
    }

    /**
     * Whether this metric exists inside a single-dimension rollup.
     *
     * Entry-derived quantities are measured again for every materialised
     * dimension, so pageviews-in-Singapore is a number that was actually
     * recorded. Session-derived ones are not: sessions are measured from
     * `cairn_sessions`, which carries no dimension columns, so they exist only
     * in the site-wide "overall" rollup. Unique visitors are the same story
     * from the other direction — the counter is keyed per day and per route,
     * and nothing else.
     *
     * A report narrowed to one dimension value therefore reports the metrics
     * that were measured at that grain and omits the rest, rather than
     * printing the site-wide figure beside a filter it does not honour, or a
     * zero that reads as "none".
     *
     * A derived metric survives only if both of its components do — there is
     * no bounce rate for a country while its session count does not exist.
     */
    public function isMeasuredPerDimension(): bool
    {
        $ratio = $this->ratio();

        if ($ratio !== null) {
            return $ratio['numerator']->isMeasuredPerDimension()
                && $ratio['denominator']->isMeasuredPerDimension();
        }

        return match ($this) {
            self::Sessions, self::Bounces, self::SessionSeconds, self::Visitors => false,
            default => $this->isStored(),
        };
    }

    /**
     * Whether values of this metric are only measurable with the JS beacon.
     *
     * Widgets built on these render an explanatory empty state when the beacon
     * is disabled, rather than reporting a misleading zero.
     */
    public function requiresBeacon(): bool
    {
        return match ($this) {
            self::TimeOnPageSeconds,
            self::TimeOnPageSamples,
            self::ScrollDepthTotal,
            self::ScrollDepthSamples,
            self::AvgTimeOnPage,
            self::AvgScrollDepth => true,
            default => false,
        };
    }

    /**
     * How a renderer should format this metric.
     */
    public function unit(): MetricUnit
    {
        return match ($this) {
            self::BounceRate, self::ConversionRate => MetricUnit::Percentage,
            self::AvgScrollDepth => MetricUnit::Percentage,
            self::SessionSeconds, self::TimeOnPageSeconds,
            self::AvgSessionDuration, self::AvgTimeOnPage => MetricUnit::Seconds,
            self::ResponseMilliseconds, self::AvgResponseTime => MetricUnit::Milliseconds,
            self::ConversionValue => MetricUnit::Currency,
            self::ViewsPerSession => MetricUnit::Decimal,
            default => MetricUnit::Count,
        };
    }

    /**
     * A human-readable label for dashboards and exports.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pageviews => 'Pageviews',
            self::Sessions => 'Sessions',
            self::Bounces => 'Bounces',
            self::Events => 'Events',
            self::Conversions => 'Conversions',
            self::ConversionValue => 'Conversion value',
            self::SessionSeconds => 'Total session time',
            self::TimeOnPageSeconds => 'Total time on page',
            self::TimeOnPageSamples => 'Time-on-page samples',
            self::ScrollDepthTotal => 'Total scroll depth',
            self::ScrollDepthSamples => 'Scroll-depth samples',
            self::ResponseMilliseconds => 'Total response time',
            self::ResponseSamples => 'Response-time samples',
            self::Visitors => 'Visitors',
            self::BounceRate => 'Bounce rate',
            self::AvgSessionDuration => 'Avg. session duration',
            self::AvgTimeOnPage => 'Avg. time on page',
            self::AvgScrollDepth => 'Avg. scroll depth',
            self::AvgResponseTime => 'Avg. response time',
            self::ViewsPerSession => 'Views per session',
            self::ConversionRate => 'Conversion rate',
        };
    }
}
