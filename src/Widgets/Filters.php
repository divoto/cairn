<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets;

use Carbon\CarbonImmutable;
use Divoto\Cairn\Enums\Comparison;
use Divoto\Cairn\Enums\Period;
use Illuminate\Http\Request;

/**
 * What the dashboard is currently showing.
 *
 * Every filter lives in the query string, so every view of the dashboard is a
 * URL: linkable, bookmarkable, shareable with a colleague, and reachable with
 * JavaScript disabled. Nothing about the current view is held in a session or
 * in component state.
 */
final readonly class Filters
{
    /**
     * The ranges the dashboard offers, as day counts.
     *
     * Kept short and named rather than free-form: an analytics dashboard with
     * an arbitrary date picker invites windows that cross a retention
     * boundary, where half the range has raw data and half does not.
     *
     * @var array<string, int>
     */
    private const RANGES = [
        'today' => 1,
        '7d' => 7,
        '30d' => 30,
        '90d' => 90,
        '12m' => 365,
    ];

    public function __construct(
        public string $range = '30d',
        public Comparison $comparison = Comparison::PreviousPeriod,
        public ?string $route = null,
        public ?string $country = null,
        public ?string $channel = null,
    ) {}

    /**
     * Read the filters out of a request's query string.
     */
    public static function fromRequest(Request $request): self
    {
        $range = $request->query('range');
        $comparison = $request->query('compare');

        return new self(
            range: is_string($range) && isset(self::RANGES[$range]) ? $range : '30d',
            comparison: is_string($comparison)
                ? (Comparison::tryFrom($comparison) ?? Comparison::PreviousPeriod)
                : Comparison::PreviousPeriod,
            route: self::string($request, 'route'),
            country: self::string($request, 'country'),
            channel: self::string($request, 'channel'),
        );
    }

    /**
     * The window this range covers, ending at the end of today.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function window(): array
    {
        $days = self::RANGES[$this->range] ?? 30;
        $to = CarbonImmutable::now('UTC')->endOfDay();

        return [$to->subDays($days - 1)->startOfDay(), $to];
    }

    /**
     * The bucket size to chart this range at.
     *
     * A single day is shown hour by hour; a year month by month. Charting a
     * year in days would put 365 points on an axis that can legibly hold
     * about a dozen.
     */
    public function interval(): Period
    {
        return match ($this->range) {
            'today' => Period::Hour,
            '12m' => Period::Month,
            default => Period::Day,
        };
    }

    /**
     * The ranges available, mapped to their labels.
     *
     * @return array<string, string>
     */
    public static function ranges(): array
    {
        return [
            'today' => 'Today',
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
            '90d' => 'Last 90 days',
            '12m' => 'Last 12 months',
        ];
    }

    /**
     * The label for the current range.
     */
    public function rangeLabel(): string
    {
        return self::ranges()[$this->range] ?? 'Last 30 days';
    }

    /**
     * Whether any dimension filter is applied.
     */
    public function isFiltered(): bool
    {
        return $this->route !== null || $this->country !== null || $this->channel !== null;
    }

    /**
     * The active dimension filters, for display as removable chips.
     *
     * @return array<string, string>
     */
    public function active(): array
    {
        return array_filter([
            'route' => $this->route,
            'country' => $this->country,
            'channel' => $this->channel,
        ], static fn (?string $value): bool => $value !== null);
    }

    /**
     * These filters as query-string parameters.
     *
     * @param  array<string, string|null>  $overrides
     * @return array<string, string>
     */
    public function toQuery(array $overrides = []): array
    {
        $query = array_merge([
            'range' => $this->range,
            'compare' => $this->comparison->value,
            'route' => $this->route,
            'country' => $this->country,
            'channel' => $this->channel,
        ], $overrides);

        return array_filter(
            $query,
            static fn (?string $value): bool => $value !== null && $value !== '',
        );
    }

    /**
     * A copy with one dimension filter added or replaced.
     */
    public function with(string $dimension, ?string $value): self
    {
        return new self(
            range: $this->range,
            comparison: $this->comparison,
            route: $dimension === 'route' ? $value : $this->route,
            country: $dimension === 'country' ? $value : $this->country,
            channel: $dimension === 'channel' ? $value : $this->channel,
        );
    }

    private static function string(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        // Length-capped: these end up in a query against an indexed column,
        // and an unbounded value from a URL is an invitation.
        return $trimmed === '' ? null : mb_substr($trimmed, 0, 255);
    }
}
