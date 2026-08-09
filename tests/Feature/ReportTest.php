<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Divoto\Cairn\Contracts\Presence;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Contracts\UniqueCounter;
use Divoto\Cairn\Data\Entry;
use Divoto\Cairn\Data\ReportRow;
use Divoto\Cairn\Enums\Comparison;
use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\EntryType;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Enums\Period;
use Divoto\Cairn\Exceptions\UnavailableDimensionException;
use Divoto\Cairn\Facades\Cairn;
use Divoto\Cairn\Reporting\Report;
use Divoto\Cairn\Support\Tables;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| A fixed dataset
|--------------------------------------------------------------------------
|
| Every expectation below is hand-calculated from this, not derived from the
| code under test. Two days, four routes, two countries:
|
|   2026-03-14   pricing.index  GB   pageview
|                pricing.index  GB   pageview
|                home.index     DE   pageview
|                (conversion, 25.00)
|   2026-03-15   pricing.index  GB   pageview
|                docs.index     DE   pageview
|
| Totals: 5 pageviews, 1 conversion worth 25.00.
| By route: pricing 3, home 1, docs 1.
| By day:   14th → 3 pageviews, 15th → 2 pageviews.
|
*/

function record(
    string $at,
    string $route = 'pricing.index',
    string $country = 'GB',
    ?EntryType $type = null,
    ?string $value = null,
): void {
    /** @var Collection<int, Entry> $collection */
    $collection = new Collection([new Entry(
        occurredAt: CarbonImmutable::parse($at, 'UTC'),
        type: $type ?? EntryType::Pageview,
        visitor: random_bytes(16),
        name: $type === EntryType::Conversion ? 'purchase' : null,
        route: $route,
        url: '/'.$route,
        country: $country,
        value: $value,
    )]);

    app(Storage::class)->store($collection);
}

function seedDataset(): void
{
    record('2026-03-14 09:00:00', 'pricing.index', 'GB');
    record('2026-03-14 10:00:00', 'pricing.index', 'GB');
    record('2026-03-14 11:00:00', 'home.index', 'DE');
    record('2026-03-14 12:00:00', 'pricing.index', 'GB', EntryType::Conversion, '25.00');
    record('2026-03-15 09:00:00', 'pricing.index', 'GB');
    record('2026-03-15 10:00:00', 'docs.index', 'DE');

    app(Storage::class)->rollup(
        CarbonImmutable::parse('2026-03-14', 'UTC'),
        CarbonImmutable::parse('2026-03-15 23:59:59', 'UTC'),
        Period::Day,
    );
}

function aReport(): Report
{
    return Cairn::report()->between(
        CarbonImmutable::parse('2026-03-14 00:00:00', 'UTC'),
        CarbonImmutable::parse('2026-03-15 23:59:59', 'UTC'),
    );
}

/*
|--------------------------------------------------------------------------
| Totals
|--------------------------------------------------------------------------
*/

it('totals a metric across the window', function (): void {
    seedDataset();

    expect(aReport()->metrics(Metric::Pageviews)->total()->metric(Metric::Pageviews))->toBe(5.0);
});

it('reports several metrics at once', function (): void {
    seedDataset();

    $row = aReport()->metrics(Metric::Pageviews, Metric::Conversions, Metric::ConversionValue)->total();

    expect($row->metric(Metric::Pageviews))->toBe(5.0)
        ->and($row->metric(Metric::Conversions))->toBe(1.0)
        ->and($row->metric(Metric::ConversionValue))->toBe(25.0);
});

it('reports zero for a window with no data', function (): void {
    seedDataset();

    $row = Cairn::report()
        ->between(CarbonImmutable::parse('2020-01-01', 'UTC'), CarbonImmutable::parse('2020-01-02', 'UTC'))
        ->metrics(Metric::Pageviews)
        ->total();

    expect($row->metric(Metric::Pageviews))->toBe(0.0);
});

/*
|--------------------------------------------------------------------------
| Grouping
|--------------------------------------------------------------------------
*/

it('breaks a metric down by a dimension', function (): void {
    seedDataset();

    $rows = aReport()
        ->metrics(Metric::Pageviews)
        ->groupBy(Dimension::Route)
        ->orderByDesc(Metric::Pageviews)
        ->get();

    expect($rows)->toHaveCount(3)
        ->and(row($rows, 0)->dimension('route'))->toBe('pricing.index')
        ->and(row($rows, 0)->metric(Metric::Pageviews))->toBe(3.0)
        ->and(row($rows, 1)->metric(Metric::Pageviews))->toBe(1.0);
});

it('breaks a metric down by country', function (): void {
    seedDataset();

    $rows = aReport()
        ->metrics(Metric::Pageviews)
        ->groupBy(Dimension::Country)
        ->orderByDesc(Metric::Pageviews)
        ->get();

    expect($rows)->toHaveCount(2)
        ->and(row($rows, 0)->dimension('country'))->toBe('GB')
        ->and(row($rows, 0)->metric(Metric::Pageviews))->toBe(3.0)
        ->and(row($rows, 1)->dimension('country'))->toBe('DE')
        ->and(row($rows, 1)->metric(Metric::Pageviews))->toBe(2.0);
});

it('honours a limit', function (): void {
    seedDataset();

    expect(aReport()->metrics(Metric::Pageviews)->groupBy(Dimension::Route)->limit(2)->get())
        ->toHaveCount(2);
});

it('sorts ascending when asked', function (): void {
    seedDataset();

    $rows = aReport()->metrics(Metric::Pageviews)->groupBy(Dimension::Route)->orderBy(Metric::Pageviews)->get();

    expect(row($rows, 0)->metric(Metric::Pageviews))->toBe(1.0)
        ->and(row($rows, 2)->metric(Metric::Pageviews))->toBe(3.0);
});

it('filters to a single dimension value', function (): void {
    seedDataset();

    $rows = aReport()
        ->metrics(Metric::Pageviews)
        ->groupBy(Dimension::Route)
        ->filter(Dimension::Route, 'pricing.index')
        ->get();

    expect($rows)->toHaveCount(1)
        ->and(row($rows, 0)->metric(Metric::Pageviews))->toBe(3.0);
});

/*
|--------------------------------------------------------------------------
| Timeseries
|--------------------------------------------------------------------------
*/

it('returns one row per bucket', function (): void {
    seedDataset();

    $series = aReport()->metrics(Metric::Pageviews)->interval(Period::Day)->timeseries();

    expect($series)->toHaveCount(2)
        ->and(row($series, 0)->metric(Metric::Pageviews))->toBe(3.0)
        ->and(row($series, 1)->metric(Metric::Pageviews))->toBe(2.0)
        ->and(row($series, 0)->bucket?->toDateString())->toBe('2026-03-14');
});

/**
 * A quiet day must appear as a trough, not as a gap the eye interpolates
 * across.
 */
it('returns empty buckets as zero rather than omitting them', function (): void {
    seedDataset();

    $series = Cairn::report()
        ->between(
            CarbonImmutable::parse('2026-03-13 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-03-16 23:59:59', 'UTC'),
        )
        ->metrics(Metric::Pageviews)
        ->timeseries();

    expect($series)->toHaveCount(4)
        ->and(row($series, 0)->metric(Metric::Pageviews))->toBe(0.0)
        ->and(row($series, 3)->metric(Metric::Pageviews))->toBe(0.0);
});

/*
|--------------------------------------------------------------------------
| Visitors below a day
|--------------------------------------------------------------------------
|
| Uniqueness is counted per calendar day and cannot be subdivided: the salt
| rotates every 24 hours, so a day is the smallest set there is anything to
| deduplicate within. An hourly bucket therefore has no visitor figure, and
| must say so rather than borrowing the day's.
|
*/

function seedOneDay(): void
{
    record('2026-03-14 09:00:00');
    record('2026-03-14 10:00:00');

    // Two distinct visitors, counted against the day.
    app(UniqueCounter::class)->add('2026-03-14', 'overall', random_bytes(16));
    app(UniqueCounter::class)->add('2026-03-14', 'overall', random_bytes(16));
}

/**
 * @return Collection<int, ReportRow>
 */
function aDayOf(Period $interval): Collection
{
    app(Storage::class)->rollup(
        CarbonImmutable::parse('2026-03-14', 'UTC'),
        CarbonImmutable::parse('2026-03-14 23:59:59', 'UTC'),
        $interval,
    );

    return Cairn::report()
        ->between(
            CarbonImmutable::parse('2026-03-14 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-03-14 23:59:59', 'UTC'),
        )
        ->metrics(Metric::Pageviews, Metric::Visitors)
        ->interval($interval)
        ->timeseries();
}

it('omits visitors from an hourly series rather than repeating the day total', function (): void {
    seedOneDay();

    $series = aDayOf(Period::Hour);

    // The 09:00 bucket has its own pageview...
    expect($series)->toHaveCount(24)
        ->and(row($series, 9)->metric(Metric::Pageviews))->toBe(1.0)
        // ...but no visitor count, because the day's two visitors cannot be
        // attributed to the hour they arrived in.
        ->and(row($series, 9)->metric(Metric::Visitors))->toBeNull()
        // Not even in a quiet hour, where borrowing the day total would be
        // most obviously wrong: no pageviews, yet two visitors.
        ->and(row($series, 3)->metric(Metric::Pageviews))->toBe(0.0)
        ->and(row($series, 3)->metric(Metric::Visitors))->toBeNull();
});

it('still counts visitors when a bucket is a whole day', function (): void {
    seedOneDay();

    $series = aDayOf(Period::Day);

    expect($series)->toHaveCount(1)
        ->and(row($series, 0)->metric(Metric::Visitors))->toBe(2.0);
});

/**
 * The headline figure is not a bucket in the series — it covers the whole
 * window, and for "today" that window is a day. Dropping the hourly figure
 * must not drop the total as well.
 */
it('still reports visitors for the window when charting it hourly', function (): void {
    seedOneDay();

    app(Storage::class)->rollup(
        CarbonImmutable::parse('2026-03-14', 'UTC'),
        CarbonImmutable::parse('2026-03-14 23:59:59', 'UTC'),
        Period::Day,
    );

    $total = Cairn::report()
        ->between(
            CarbonImmutable::parse('2026-03-14 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-03-14 23:59:59', 'UTC'),
        )
        ->metrics(Metric::Pageviews, Metric::Visitors)
        ->interval(Period::Hour)
        ->total();

    expect($total->metric(Metric::Visitors))->toBe(2.0);
});

/*
|--------------------------------------------------------------------------
| Derived metrics
|--------------------------------------------------------------------------
|
| The reason Metric splits additive from derived. A week's bounce rate must be
| that week's bounces over that week's sessions, never the mean of seven daily
| rates — the two differ whenever traffic varies between days, which is always.
|
*/

it('computes a rate from its components at the level it is displayed', function (): void {
    // Day one: 10 sessions, 8 bounces (80%). Day two: 90 sessions, 9 (10%).
    // Correct combined rate: 17/100 = 17%. Mean of the daily rates: 45%.
    $connection = app(DatabaseManager::class)->connection(Tables::connection());

    foreach ([['2026-03-14', 10, 8], ['2026-03-15', 90, 9]] as [$day, $sessions, $bounces]) {
        foreach ([[Metric::Sessions->value, $sessions], [Metric::Bounces->value, $bounces]] as [$type, $value]) {
            $connection->table(Tables::aggregates())->insert([
                'bucket' => CarbonImmutable::parse($day, 'UTC')->getTimestamp(),
                'period' => Period::Day->value,
                'type' => $type,
                'aggregate' => 'overall',
                'key' => '[]',
                'key_hash' => binaryColumn(substr(hash('sha256', '[]', true), 0, 16)),
                'value' => $value,
                'tenant_id' => '',
            ]);
        }
    }

    $rate = aReport()->metrics(Metric::BounceRate)->total()->metric(Metric::BounceRate);

    expect($rate)->toBe(0.17)
        ->and($rate)->not->toBe(0.45);
});

/**
 * There is no bounce rate for zero sessions. Rendering 0% would be a claim the
 * data does not support.
 */
it('omits a rate whose denominator is zero rather than reporting zero', function (): void {
    seedDataset();

    expect(aReport()->metrics(Metric::BounceRate)->total()->metric(Metric::BounceRate))->toBeNull();
});

it('reads only the components a derived metric needs', function (): void {
    seedDataset();

    // ViewsPerSession is pageviews over sessions; with no sessions recorded
    // the ratio is unavailable rather than infinite.
    expect(aReport()->metrics(Metric::ViewsPerSession)->total()->metric(Metric::ViewsPerSession))
        ->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Comparison
|--------------------------------------------------------------------------
*/

it('attaches the previous window and computes a change', function (): void {
    // Two days back: 1 pageview. Current two days: 5.
    record('2026-03-12 09:00:00');
    record('2026-03-14 09:00:00');
    record('2026-03-14 10:00:00');
    record('2026-03-15 09:00:00');

    app(Storage::class)->rollup(
        CarbonImmutable::parse('2026-03-10', 'UTC'),
        CarbonImmutable::parse('2026-03-15 23:59:59', 'UTC'),
        Period::Day,
    );

    $row = aReport()
        ->metrics(Metric::Pageviews)
        ->compare(Comparison::PreviousPeriod)
        ->total();

    expect($row->metric(Metric::Pageviews))->toBe(3.0)
        ->and($row->previous[Metric::Pageviews->value] ?? null)->toBe(1.0)
        ->and($row->change(Metric::Pageviews))->toBe(2.0);
});

/**
 * A zero baseline has no meaningful percentage change. Reporting "+100%" or
 * infinity would invent a number the data does not contain.
 */
it('returns no change against a zero baseline', function (): void {
    seedDataset();

    $row = aReport()->metrics(Metric::Pageviews)->compare(Comparison::PreviousPeriod)->total();

    expect($row->metric(Metric::Pageviews))->toBe(5.0)
        ->and($row->previous[Metric::Pageviews->value] ?? null)->toBe(0.0)
        ->and($row->change(Metric::Pageviews))->toBeNull();
});

it('compares against the same window a year earlier', function (): void {
    $window = Comparison::PreviousYear->windowFor(
        CarbonImmutable::parse('2026-03-14', 'UTC'),
        CarbonImmutable::parse('2026-03-15', 'UTC'),
    );

    expect($window)->not->toBeNull();

    [$from, $to] = $window ?? [CarbonImmutable::now(), CarbonImmutable::now()];

    expect($from->toDateString())->toBe('2025-03-14')
        ->and($to->toDateString())->toBe('2025-03-15');
});

/**
 * The comparison window must be the same length as the current one, or "up
 * 12%" means nothing. An inclusive window is one second short of a whole day,
 * so that second has to be added back.
 */
it('gives the previous period the same length as the current one', function (): void {
    $from = CarbonImmutable::parse('2026-03-14 00:00:00', 'UTC');
    $to = CarbonImmutable::parse('2026-03-15 23:59:59', 'UTC');

    $window = Comparison::PreviousPeriod->windowFor($from, $to);

    expect($window)->not->toBeNull();

    [$previousFrom, $previousTo] = $window ?? [CarbonImmutable::now(), CarbonImmutable::now()];

    expect($previousFrom->toDateTimeString())->toBe('2026-03-12 00:00:00')
        ->and($previousTo->toDateTimeString())->toBe('2026-03-13 23:59:59');
});

/*
|--------------------------------------------------------------------------
| Unique visitors
|--------------------------------------------------------------------------
*/

it('sums daily unique counts across the window', function (): void {
    app(UniqueCounter::class)->add('2026-03-14', 'overall', random_bytes(16));
    app(UniqueCounter::class)->add('2026-03-14', 'overall', random_bytes(16));
    app(UniqueCounter::class)->add('2026-03-15', 'overall', random_bytes(16));

    expect(aReport()->metrics(Metric::Visitors)->total()->metric(Metric::Visitors))->toBe(3.0);
});

/**
 * Summing daily uniques counts a returning visitor once per day. That is the
 * direct consequence of rotating the salt, so the row says so rather than
 * presenting an estimate as exact.
 */
it('flags a multi-day visitor count as approximate', function (): void {
    app(UniqueCounter::class)->add('2026-03-14', 'overall', random_bytes(16));

    expect(aReport()->metrics(Metric::Visitors)->total()->approximate)->toBeTrue();
});

it('does not flag a single day as approximate', function (): void {
    app(UniqueCounter::class)->add('2026-03-14', 'overall', random_bytes(16));

    $row = Cairn::report()
        ->between(
            CarbonImmutable::parse('2026-03-14 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-03-14 23:59:59', 'UTC'),
        )
        ->metrics(Metric::Visitors)
        ->total();

    expect($row->approximate)->toBeFalse();
});

it('does not flag a report without visitors as approximate', function (): void {
    seedDataset();

    expect(aReport()->metrics(Metric::Pageviews)->total()->approximate)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Refusing what was never materialised
|--------------------------------------------------------------------------
|
| The rule that keeps the dashboard fast: throw, naming the combination, rather
| than quietly scanning raw entries.
|
*/

it('refuses to group by a dimension that is not rolled up', function (Dimension $dimension): void {
    expect(fn (): Collection => aReport()->groupBy($dimension)->get())
        ->toThrow(UnavailableDimensionException::class, $dimension->value);
})->with([
    'url' => [Dimension::Url],
    'region' => [Dimension::Region],
    'city' => [Dimension::City],
    'screen class' => [Dimension::ScreenClass],
]);

it('refuses to combine two dimensions', function (): void {
    expect(fn (): Collection => aReport()->groupBy(Dimension::Route)->filter(Dimension::Country, 'GB')->get())
        ->toThrow(UnavailableDimensionException::class, 'single-dimension rollups only');
});

it('refuses unique visitors at a grouping they are not counted for', function (): void {
    expect(fn (): Collection => aReport()->metrics(Metric::Visitors)->groupBy(Dimension::Country)->get())
        ->toThrow(UnavailableDimensionException::class, 'visitors');
});

it('allows unique visitors grouped by route, which is counted', function (): void {
    expect(fn (): Collection => aReport()->metrics(Metric::Visitors)->groupBy(Dimension::Route)->get())
        ->not->toThrow(UnavailableDimensionException::class);
});

/**
 * The acceptance criterion: the builder never touches raw entries.
 */
it('issues no query against cairn_entries', function (): void {
    seedDataset();

    $touched = false;

    app(DatabaseManager::class)->connection(Tables::connection())->listen(
        function (QueryExecuted $query) use (&$touched): void {
            if (str_contains($query->sql, Tables::entries())) {
                $touched = true;
            }
        }
    );

    aReport()->metrics(Metric::Pageviews, Metric::Conversions)->groupBy(Dimension::Route)->get();
    aReport()->metrics(Metric::Pageviews)->timeseries();
    aReport()->metrics(Metric::Pageviews)->compare(Comparison::PreviousPeriod)->total();

    expect($touched)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Output formats
|--------------------------------------------------------------------------
*/

it('exports to an array', function (): void {
    seedDataset();

    $array = aReport()->metrics(Metric::Pageviews)->groupBy(Dimension::Route)->toArray();

    expect($array)->toHaveCount(3)
        ->and($array[0])->toHaveKeys(['dimensions', 'metrics']);
});

it('exports to CSV with a header row', function (): void {
    seedDataset();

    $csv = aReport()
        ->metrics(Metric::Pageviews)
        ->groupBy(Dimension::Route)
        ->orderByDesc(Metric::Pageviews)
        ->toCsv();

    $lines = array_values(array_filter(explode("\n", trim($csv))));

    expect($lines[0])->toBe('route,pageviews')
        ->and($lines[1])->toBe('pricing.index,3');
});

it('exports nothing for an empty report', function (): void {
    expect(aReport()->metrics(Metric::Pageviews)->groupBy(Dimension::Route)->toCsv())->toBe('');
});

/*
|--------------------------------------------------------------------------
| Builder behaviour
|--------------------------------------------------------------------------
*/

it('returns a fresh builder each time, so widgets cannot contaminate each other', function (): void {
    $one = Cairn::report()->filter(Dimension::Route, 'pricing.index');
    $two = Cairn::report();

    expect($one)->not->toBe($two)
        ->and(fn () => $two->groupBy(Dimension::Country)->get())
        ->not->toThrow(UnavailableDimensionException::class);
});

it('defaults to the last 30 days', function (): void {
    $series = Cairn::report()->metrics(Metric::Pageviews)->timeseries();

    expect($series)->toHaveCount(30);
});

it('reports live visitors from presence rather than aggregates', function (): void {
    app(Presence::class)->touch(random_bytes(16), '/pricing');

    expect(Cairn::report()->realtime())->toBe(1);
});
