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
        public ?string $language = null,
        public ?string $screenClass = null,
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
            language: self::string($request, 'language'),
            screenClass: self::string($request, 'screen_class'),
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
     * Whether a dimension filter is applied.
     */
    public function isFiltered(): bool
    {
        return $this->active() !== [];
    }

    /**
     * The active dimension filter, for display as a removable chip.
     *
     * **At most one.** v1 materialises single-dimension rollups, so "traffic
     * from Singapore" is a number that was measured and "traffic from
     * Singapore on the pricing page" is not. Two filters at once would name a
     * figure nothing can answer, so a hand-written URL carrying both is read
     * as the first by this precedence rather than half-honoured.
     *
     * @return array<string, string>
     */
    public function active(): array
    {
        foreach ([
            'route' => $this->route,
            'country' => $this->country,
            'channel' => $this->channel,
            'language' => $this->language,
            'screen_class' => $this->screenClass,
        ] as $dimension => $value) {
            if ($value !== null) {
                return [$dimension => $value];
            }
        }

        return [];
    }

    /**
     * The dimension currently filtered on, if any.
     */
    public function dimension(): ?string
    {
        return array_key_first($this->active());
    }

    /**
     * These filters as query-string parameters.
     *
     * @param  array<string, string|null>  $overrides
     * @return array<string, string>
     */
    public function toQuery(array $overrides = []): array
    {
        // Only the honoured filter is written back, so a link built from a
        // URL carrying two never propagates the one that was ignored.
        $query = array_merge([
            'range' => $this->range,
            'compare' => $this->comparison->value,
        ], $this->active(), $overrides);

        return array_filter(
            $query,
            static fn (?string $value): bool => $value !== null && $value !== '',
        );
    }

    /**
     * A copy filtered by one dimension, replacing whatever was set before.
     *
     * Selecting a country while a route is selected *replaces* it rather than
     * adding to it, for the reason given on {@see self::active()}: the pair
     * was never rolled up. Passing null clears the filter entirely.
     */
    public function with(string $dimension, ?string $value): self
    {
        return new self(
            range: $this->range,
            comparison: $this->comparison,
            route: $dimension === 'route' ? $value : null,
            country: $dimension === 'country' ? $value : null,
            channel: $dimension === 'channel' ? $value : null,
            language: $dimension === 'language' ? $value : null,
            screenClass: $dimension === 'screen_class' ? $value : null,
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
