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
use Divoto\Cairn\Support\Binary;
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

/**
 * Seed a closed visit directly, so a bounce rate is a hand count rather than
 * something derived from the resolver.
 */
function reportVisit(string $startedAt, string $entryUrl, int $pages, bool $bounce, int $seconds): void
{
    $at = CarbonImmutable::parse($startedAt, 'UTC');
    $connection = app(DatabaseManager::class)->connection(Tables::connection());

    $connection->table(Tables::sessions())->insert([
        'id' => Binary::bind($connection, random_bytes(8)),
        'visitor' => Binary::bind($connection, random_bytes(16)),
        'started_at' => $at->toDateTimeString(),
        'last_activity_at' => $at->addSeconds($seconds)->toDateTimeString(),
        'page_count' => $pages,
        'duration_seconds' => $seconds,
        'entry_url' => $entryUrl,
        'exit_url' => $entryUrl,
        'is_bounce' => $bounce,
        'tenant_id' => '',
    ]);
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

/**
 * Each point on a chart counts its own bucket and no other.
 *
 * The window handed to the visitor count is inclusive at both ends, like every
 * other window in the report builder, but a bucket's natural end is the start
 * of the next one. Read as inclusive, that bound put the following day inside
 * every bucket: a daily chart reported each day's visitors plus tomorrow's,
 * and only the last point — which had no tomorrow with data — was right.
 *
 * Seeded with three days of very different sizes so a leak in either direction
 * is unmistakable rather than a plausible-looking number.
 */
it('counts only its own day in each bucket of a series', function (): void {
    record('2026-03-14 09:00:00');
    record('2026-03-15 09:00:00');
    record('2026-03-16 09:00:00');

    foreach ([['2026-03-14', 2], ['2026-03-15', 5], ['2026-03-16', 11]] as [$day, $visitors]) {
        foreach (range(1, $visitors) as $visitor) {
            app(UniqueCounter::class)->add(
                $day,
                'overall',
                substr(hash('sha256', $day.'-'.$visitor, true), 0, 16),
            );
        }
    }

    app(Storage::class)->rollup(
        CarbonImmutable::parse('2026-03-14', 'UTC'),
        CarbonImmutable::parse('2026-03-16 23:59:59', 'UTC'),
        Period::Day,
    );

    $series = Cairn::report()
        ->between(
            CarbonImmutable::parse('2026-03-14 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-03-16 23:59:59', 'UTC'),
        )
        ->metrics(Metric::Pageviews, Metric::Visitors)
        ->interval(Period::Day)
        ->timeseries();

    expect($series)->toHaveCount(3)
        ->and(row($series, 0)->metric(Metric::Visitors))->toBe(2.0)
        ->and(row($series, 1)->metric(Metric::Visitors))->toBe(5.0)
        ->and(row($series, 2)->metric(Metric::Visitors))->toBe(11.0)
        // And the totals row is still the sum of the three, which it was
        // before: the window bound was only ever wrong per bucket.
        ->and($series->sum(static fn (ReportRow $row): float => $row->metric(Metric::Visitors) ?? 0.0))
        ->toBe(18.0);
});

/**
 * A single day's visitor count is an exact distinct count, not a sum of
 * several, so a daily bucket carries no approximation warning.
 */
it('does not flag a daily bucket of a series as approximate', function (): void {
    seedOneDay();

    expect(row(aDayOf(Period::Day), 0)->approximate)->toBeFalse();
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
]);

/**
 * The one place the report builder reads a table other than cairn_aggregates'
 * entry-derived rows. A landing page is a column on cairn_sessions, so a
 * report grouped by it can answer with a bounce rate — which no other grouping
 * on the dashboard can.
 */
it('reports a bounce rate for a report grouped by landing page', function (): void {
    reportVisit('2026-03-14 09:00:00', '/pricing', pages: 3, bounce: false, seconds: 180);
    reportVisit('2026-03-14 09:30:00', '/pricing', pages: 1, bounce: true, seconds: 0);

    app(Storage::class)->rollup(
        CarbonImmutable::parse('2026-03-14 00:00:00', 'UTC'),
        CarbonImmutable::parse('2026-03-14 23:59:59', 'UTC'),
        Period::Day,
    );

    $row = aReport()
        ->metrics(Metric::Sessions, Metric::BounceRate)
        ->groupBy(Dimension::EntryPage)
        ->get()
        ->first();

    expect($row?->dimension('entry_url'))->toBe('/pricing')
        ->and($row?->metric(Metric::Sessions))->toBe(2.0)
        ->and($row?->metric(Metric::BounceRate))->toBe(0.5);
});

/**
 * Session-sourced or not, v1 still materialises one dimension at a time. A
 * landing page combined with a country is a pair nothing ever measured.
 */
it('refuses to combine a landing page with another dimension', function (): void {
    expect(fn (): Collection => aReport()
        ->groupBy(Dimension::EntryPage)
        ->filter(Dimension::Country, 'GB')
        ->get())
        ->toThrow(UnavailableDimensionException::class, 'single-dimension rollups only');
});

it('refuses to filter by a dimension that is not rolled up', function (): void {
    expect(fn (): Collection => aReport()->filter(Dimension::Url, '/pricing')->get())
        ->toThrow(UnavailableDimensionException::class, Dimension::Url->value);
});

it('refuses to combine two dimensions', function (): void {
    expect(fn (): Collection => aReport()->groupBy(Dimension::Route)->filter(Dimension::Country, 'GB')->get())
        ->toThrow(UnavailableDimensionException::class, 'single-dimension rollups only');
});

/*
|--------------------------------------------------------------------------
| Narrowing without grouping
|--------------------------------------------------------------------------
|
| "How much traffic came from Germany" is answerable: the country rollup holds
| it, and collapsing that rollup to a single total is exactly the question.
| The builder used to read the dimensionless "overall" rows instead, filter
| every one of them out, and report zero — a plausible number and a wrong one.
|
*/

it('totals a metric narrowed to one dimension value', function (): void {
    seedDataset();

    // Three of the five pageviews came from GB and two from DE. The fourth
    // GB entry is the conversion, which is not a pageview.
    expect(aReport()->metrics(Metric::Pageviews)->filter(Dimension::Country, 'GB')->total()->metric(Metric::Pageviews))
        ->toBe(3.0)
        ->and(aReport()->metrics(Metric::Pageviews)->filter(Dimension::Country, 'DE')->total()->metric(Metric::Pageviews))
        ->toBe(2.0);
});

it('narrows a timeseries to one dimension value', function (): void {
    seedDataset();

    $series = aReport()
        ->metrics(Metric::Pageviews)
        ->filter(Dimension::Route, 'pricing.index')
        ->interval(Period::Day)
        ->timeseries();

    // pricing.index: two pageviews on the 14th, one on the 15th.
    expect($series->map(fn (ReportRow $row): ?float => $row->metric(Metric::Pageviews))->all())
        ->toBe([2.0, 1.0]);
});

/**
 * Sessions are measured from `cairn_sessions`, which has no dimension
 * columns, so no per-country session count was ever written. The metric is
 * omitted — a zero here would read as "Germany sent no sessions", which is a
 * claim about traffic rather than about what was measured.
 */
it('omits the metrics a single-dimension rollup never measured', function (): void {
    seedDataset();

    $row = aReport()
        ->metrics(Metric::Pageviews, Metric::Sessions, Metric::Visitors, Metric::BounceRate)
        ->filter(Dimension::Country, 'GB')
        ->total();

    expect($row->metric(Metric::Pageviews))->toBe(3.0)
        ->and($row->metric(Metric::Sessions))->toBeNull()
        ->and($row->metric(Metric::Visitors))->toBeNull()
        ->and($row->metric(Metric::BounceRate))->toBeNull()
        // Approximation is a warning about summed daily visitor counts, and
        // there is no visitor count here to warn about.
        ->and($row->approximate)->toBeFalse();
});

/**
 * Uniques cannot be summed out of a rollup, so they are counted as traffic
 * arrives against keys chosen in advance — one site-wide and one per route.
 * That makes "visitors on the pricing page" a real number and "visitors from
 * Germany" one that was never counted, and the builder reports accordingly
 * rather than treating every narrowing the same.
 */
it('reports unique visitors for a route it narrows to, but not for a country', function (): void {
    seedDataset();

    app(UniqueCounter::class)->add('2026-03-14', 'route:pricing.index', random_bytes(16));
    app(UniqueCounter::class)->add('2026-03-14', 'route:pricing.index', random_bytes(16));
    app(UniqueCounter::class)->add('2026-03-14', 'route:home.index', random_bytes(16));

    $byRoute = aReport()
        ->metrics(Metric::Visitors)
        ->filter(Dimension::Route, 'pricing.index')
        ->total();

    expect($byRoute->metric(Metric::Visitors))->toBe(2.0)
        ->and(
            aReport()->metrics(Metric::Visitors)->filter(Dimension::Country, 'GB')
                ->total()->metric(Metric::Visitors)
        )->toBeNull();
});

it('keeps reporting site-wide sessions when nothing is narrowed', function (): void {
    seedDataset();

    expect(aReport()->metrics(Metric::Pageviews, Metric::Sessions)->total()->metric(Metric::Sessions))
        ->not->toBeNull();
});

/**
 * Two filters and no grouping asks for the same unmaterialised pair as
 * grouping by one and filtering by another, so it is refused the same way
 * rather than answered with whichever half happens to be applied first.
 */
it('refuses to narrow by two dimensions at once', function (): void {
    expect(fn (): ReportRow => aReport()
        ->metrics(Metric::Pageviews)
        ->filter(Dimension::Country, 'GB')
        ->filter(Dimension::Route, 'pricing.index')
        ->total())
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
| Cost
|--------------------------------------------------------------------------
|
| The dashboard's cost must not scale with how much traffic it is describing.
| Both of these started out as a query per bucket and a counter read per row
| per day, which is invisible on a seeded test database and is the reason a
| busy site's dashboard took seconds: the work per read was small, but the
| number of reads was the product of two things that both grow.
|
| These assert that the count does not grow, rather than pinning an exact
| number — the point is the shape, not the constant.
|
*/

/**
 * Count the queries a report runs.
 */
function queriesFor(callable $report): int
{
    $connection = app(DatabaseManager::class)->connection(Tables::connection());

    $count = 0;

    $connection->listen(function (QueryExecuted $query) use (&$count): void {
        $count++;
    });

    $report();

    // The listener stays registered, which is harmless: a later call's
    // queries increment a counter whose value has already been returned.
    return $count;
}

it('reads a chart in the same number of queries however many buckets it has', function (): void {
    seedDataset();

    $series = static fn (string $from, string $to): callable => static function () use ($from, $to): void {
        Cairn::report()
            ->between(
                CarbonImmutable::parse($from, 'UTC'),
                CarbonImmutable::parse($to, 'UTC'),
            )
            ->metrics(Metric::Pageviews, Metric::Visitors)
            ->interval(Period::Day)
            ->timeseries();
    };

    $oneDay = queriesFor($series('2026-03-14 00:00:00', '2026-03-14 23:59:59'));
    $ninetyDays = queriesFor($series('2026-01-01 00:00:00', '2026-03-31 23:59:59'));

    expect($ninetyDays)->toBe($oneDay);
});

it('reads an hourly chart in the same number of queries as a daily one', function (): void {
    seedDataset();

    $series = static fn (Period $interval): callable => static function () use ($interval): void {
        Cairn::report()
            ->between(
                CarbonImmutable::parse('2026-03-14 00:00:00', 'UTC'),
                CarbonImmutable::parse('2026-03-15 23:59:59', 'UTC'),
            )
            ->metrics(Metric::Pageviews)
            ->interval($interval)
            ->timeseries();
    };

    expect(queriesFor($series(Period::Hour)))->toBe(queriesFor($series(Period::Day)));
});

/**
 * A ranked table asks the unique counter once for the whole table. Grouped by
 * route, which is the one dimension visitors are counted against, so every row
 * has a counter key of its own to look up.
 */
it('reads a ranked table in the same number of queries however many rows it has', function (): void {
    $table = static fn (int $routes): callable => static function (): void {
        Cairn::report()
            ->between(
                CarbonImmutable::parse('2026-03-14 00:00:00', 'UTC'),
                CarbonImmutable::parse('2026-03-15 23:59:59', 'UTC'),
            )
            ->metrics(Metric::Pageviews, Metric::Visitors)
            ->groupBy(Dimension::Route)
            ->get();
    };

    record('2026-03-14 09:00:00', 'route.1');
    app(Storage::class)->rollup(
        CarbonImmutable::parse('2026-03-14', 'UTC'),
        CarbonImmutable::parse('2026-03-15 23:59:59', 'UTC'),
        Period::Day,
    );
    $oneRoute = queriesFor($table(1));

    foreach (range(2, 40) as $route) {
        record('2026-03-14 09:00:00', 'route.'.$route);
    }

    app(Storage::class)->rollup(
        CarbonImmutable::parse('2026-03-14', 'UTC'),
        CarbonImmutable::parse('2026-03-15 23:59:59', 'UTC'),
        Period::Day,
    );
    $fortyRoutes = queriesFor($table(40));

    expect($fortyRoutes)->toBe($oneRoute);
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
