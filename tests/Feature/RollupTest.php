<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Data\Entry;
use Divoto\Cairn\Enums\EntryType;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Enums\Period;
use Divoto\Cairn\Support\Tables;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

function aggregates(): Builder
{
    return app(DatabaseManager::class)
        ->connection(Tables::connection())
        ->table(Tables::aggregates());
}

/**
 * Seed a raw entry at a fixed instant, so every expectation below is
 * hand-calculable rather than derived from the code under test.
 */
function seedEntry(
    string $at,
    ?EntryType $type = null,
    ?string $name = null,
    string $route = 'pricing.index',
    string $country = 'GB',
    ?string $value = null,
): void {
    $entry = new Entry(
        occurredAt: CarbonImmutable::parse($at, 'UTC'),
        type: $type ?? EntryType::Pageview,
        visitor: random_bytes(16),
        name: $name,
        route: $route,
        url: '/pricing',
        country: $country,
        durationMs: 20,
        value: $value,
    );

    /** @var Collection<int, Entry> $collection */
    $collection = new Collection([$entry]);

    app(Storage::class)->store($collection);
}

function metricFor(string $type, string $aggregate = 'overall'): float
{
    $value = aggregates()
        ->where('type', $type)
        ->where('aggregate', $aggregate)
        ->sum('value');

    return is_numeric($value) ? (float) $value : 0.0;
}

/*
|--------------------------------------------------------------------------
| Idempotency
|--------------------------------------------------------------------------
|
| The acceptance criterion for this phase: running the same rollup twice must
| leave identical rows. It is what makes the command safe to run over any
| window at any time, and what lets it repair a drifted live path.
|
*/

it('produces identical aggregates when run twice over the same window', function (): void {
    seedEntry('2026-03-14 09:00:00');
    seedEntry('2026-03-14 11:30:00');

    $from = CarbonImmutable::parse('2026-03-14 00:00:00', 'UTC');
    $to = CarbonImmutable::parse('2026-03-14 23:59:59', 'UTC');

    app(Storage::class)->rollup($from, $to, Period::Day);
    $first = aggregates()->orderBy('type')->orderBy('key')->get()->toJson();

    app(Storage::class)->rollup($from, $to, Period::Day);
    $second = aggregates()->orderBy('type')->orderBy('key')->get()->toJson();

    expect($second)->toBe($first);
});

it('does not accumulate rows on repeated runs', function (): void {
    seedEntry('2026-03-14 09:00:00');

    $from = CarbonImmutable::parse('2026-03-14 00:00:00', 'UTC');
    $to = CarbonImmutable::parse('2026-03-14 23:59:59', 'UTC');

    app(Storage::class)->rollup($from, $to, Period::Day);
    $after = aggregates()->count();

    foreach (range(1, 3) as $ignored) {
        app(Storage::class)->rollup($from, $to, Period::Day);
    }

    expect(aggregates()->count())->toBe($after);
});

/**
 * A rollup run after entries were deleted must remove the aggregates too,
 * rather than leaving the previous numbers behind — that is what makes it a
 * repair path rather than an accumulator.
 */
it('rebuilds rather than increments', function (): void {
    seedEntry('2026-03-14 09:00:00');

    $from = CarbonImmutable::parse('2026-03-14 00:00:00', 'UTC');
    $to = CarbonImmutable::parse('2026-03-14 23:59:59', 'UTC');

    app(Storage::class)->rollup($from, $to, Period::Day);
    expect(metricFor(Metric::Pageviews->value))->toBe(1.0);

    app(DatabaseManager::class)->connection(Tables::connection())->table(Tables::entries())->delete();

    app(Storage::class)->rollup($from, $to, Period::Day);
    expect(metricFor(Metric::Pageviews->value))->toBe(0.0)
        ->and(aggregates()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Arithmetic
|--------------------------------------------------------------------------
*/

it('counts pageviews, events and conversions separately', function (): void {
    seedEntry('2026-03-14 09:00:00');
    seedEntry('2026-03-14 09:05:00');
    seedEntry('2026-03-14 09:10:00', type: EntryType::Event, name: 'signed_up');
    seedEntry('2026-03-14 09:15:00', type: EntryType::Conversion, name: 'purchase', value: '25.00');

    app(Storage::class)->rollup(
        CarbonImmutable::parse('2026-03-14 00:00:00', 'UTC'),
        CarbonImmutable::parse('2026-03-14 23:59:59', 'UTC'),
        Period::Day,
    );

    expect(metricFor(Metric::Pageviews->value))->toBe(2.0)
        ->and(metricFor(Metric::Events->value))->toBe(1.0)
        ->and(metricFor(Metric::Conversions->value))->toBe(1.0)
        ->and(metricFor(Metric::ConversionValue->value))->toBe(25.0);
});

/**
 * The acceptance criterion: a month's additive metrics equal the sum of that
 * month's days. If this ever stops holding, every long-range report is wrong
 * in a way nobody would notice from the dashboard.
 */
it('gives a month the same total as the sum of its days', function (): void {
    foreach (['2026-03-01 10:00:00', '2026-03-01 11:00:00', '2026-03-14 09:00:00', '2026-03-31 23:00:00'] as $at) {
        seedEntry($at);
    }

    $from = CarbonImmutable::parse('2026-03-01 00:00:00', 'UTC');
    $to = CarbonImmutable::parse('2026-03-31 23:59:59', 'UTC');

    app(Storage::class)->rollup($from, $to, Period::Day);

    $sumOfDays = aggregates()
        ->where('period', Period::Day->value)
        ->where('aggregate', 'overall')
        ->where('type', Metric::Pageviews->value)
        ->sum('value');

    aggregates()->delete();

    app(Storage::class)->rollup($from, $to, Period::Month);

    $month = aggregates()
        ->where('period', Period::Month->value)
        ->where('aggregate', 'overall')
        ->where('type', Metric::Pageviews->value)
        ->sum('value');

    expect(columnFloat($month))->toBe(columnFloat($sumOfDays))
        ->and(columnFloat($month))->toBe(4.0);
});

it('keeps each day in its own bucket', function (): void {
    seedEntry('2026-03-14 09:00:00');
    seedEntry('2026-03-15 09:00:00');
    seedEntry('2026-03-15 10:00:00');

    app(Storage::class)->rollup(
        CarbonImmutable::parse('2026-03-14 00:00:00', 'UTC'),
        CarbonImmutable::parse('2026-03-15 23:59:59', 'UTC'),
        Period::Day,
    );

    $byBucket = aggregates()
        ->where('aggregate', 'overall')
        ->where('type', Metric::Pageviews->value)
        ->pluck('value', 'bucket')
        ->map(static fn (mixed $v): float => is_numeric($v) ? (float) $v : 0.0)
        ->all();

    expect($byBucket)->toHaveCount(2)
        ->and(array_values($byBucket))->toBe([1.0, 2.0]);
});

it('materialises single-dimension breakdowns', function (): void {
    seedEntry('2026-03-14 09:00:00', route: 'pricing.index', country: 'GB');
    seedEntry('2026-03-14 09:05:00', route: 'pricing.index', country: 'DE');
    seedEntry('2026-03-14 09:10:00', route: 'home.index', country: 'GB');

    app(Storage::class)->rollup(
        CarbonImmutable::parse('2026-03-14 00:00:00', 'UTC'),
        CarbonImmutable::parse('2026-03-14 23:59:59', 'UTC'),
        Period::Day,
    );

    expect(metricFor(Metric::Pageviews->value, 'route'))->toBe(3.0)
        ->and(metricFor(Metric::Pageviews->value, 'country'))->toBe(3.0);

    $pricing = aggregates()
        ->where('aggregate', 'route')
        ->where('type', Metric::Pageviews->value)
        ->where('key', json_encode(['route' => 'pricing.index']))
        ->value('value');

    expect(columnFloat($pricing))->toBe(2.0);
});

/**
 * Zeroes are not stored. An aggregate table recording that nothing happened
 * grows with the dimension space rather than with the traffic.
 */
it('stores no rows for metrics that measured nothing', function (): void {
    seedEntry('2026-03-14 09:00:00');

    app(Storage::class)->rollup(
        CarbonImmutable::parse('2026-03-14 00:00:00', 'UTC'),
        CarbonImmutable::parse('2026-03-14 23:59:59', 'UTC'),
        Period::Day,
    );

    expect(aggregates()->where('type', Metric::Conversions->value)->count())->toBe(0)
        ->and(aggregates()->where('value', 0)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Session-derived metrics
|--------------------------------------------------------------------------
|
| Sessions live in their own table, so they are measured separately. Without
| this, bounce rate and average session duration have no denominator and can
| never be reported — a gap that showed up as a permanent "0 sessions" on a
| real dashboard rather than as a failing test.
|
*/

it('rolls up sessions, bounces and session duration', function (): void {
    $connection = app(DatabaseManager::class)->connection(Tables::connection());

    foreach ([[true, 0], [true, 0], [false, 240]] as $index => [$bounced, $seconds]) {
        $connection->table(Tables::sessions())->insert([
            'id' => random_bytes(16),
            'visitor' => random_bytes(16),
            'started_at' => '2026-03-14 09:0'.$index.':00',
            'last_activity_at' => '2026-03-14 09:0'.$index.':00',
            'page_count' => $bounced ? 1 : 4,
            'duration_seconds' => $seconds,
            'is_bounce' => $bounced,
            'tenant_id' => '',
        ]);
    }

    app(Storage::class)->rollup(
        CarbonImmutable::parse('2026-03-14 00:00:00', 'UTC'),
        CarbonImmutable::parse('2026-03-14 23:59:59', 'UTC'),
        Period::Day,
    );

    expect(metricFor(Metric::Sessions->value))->toBe(3.0)
        ->and(metricFor(Metric::Bounces->value))->toBe(2.0)
        ->and(metricFor(Metric::SessionSeconds->value))->toBe(240.0);
});

/**
 * A visit that spans midnight belongs to the day it began. Any other
 * assignment stops the daily counts summing to the monthly one.
 */
it('attributes a session to the bucket it started in', function (): void {
    app(DatabaseManager::class)->connection(Tables::connection())
        ->table(Tables::sessions())->insert([
            'id' => random_bytes(16),
            'visitor' => random_bytes(16),
            'started_at' => '2026-03-14 23:50:00',
            'last_activity_at' => '2026-03-15 00:10:00',
            'page_count' => 3,
            'duration_seconds' => 1200,
            'is_bounce' => false,
            'tenant_id' => '',
        ]);

    app(Storage::class)->rollup(
        CarbonImmutable::parse('2026-03-14 00:00:00', 'UTC'),
        CarbonImmutable::parse('2026-03-15 23:59:59', 'UTC'),
        Period::Day,
    );

    $byBucket = aggregates()
        ->where('type', Metric::Sessions->value)
        ->pluck('value', 'bucket')
        ->map(static fn (mixed $v): float => is_numeric($v) ? (float) $v : 0.0)
        ->all();

    expect($byBucket)->toHaveCount(1)
        ->and(array_key_first($byBucket))
        ->toBe(CarbonImmutable::parse('2026-03-14', 'UTC')->getTimestamp());
});

/*
|--------------------------------------------------------------------------
| The command
|--------------------------------------------------------------------------
*/

it('rolls up a single day with --date', function (): void {
    seedEntry('2026-03-14 09:00:00');
    seedEntry('2026-03-15 09:00:00');

    expect(Artisan::call('cairn:rollup --date=2026-03-14'))->toBe(0);

    expect(aggregates()->where('period', Period::Day->value)->count())->toBeGreaterThan(0)
        ->and(metricFor(Metric::Pageviews->value))->toBe(1.0);
});

it('rolls up a window with --from and --to', function (): void {
    seedEntry('2026-03-14 09:00:00');
    seedEntry('2026-03-15 09:00:00');

    expect(Artisan::call('cairn:rollup --from=2026-03-14 --to=2026-03-15'))->toBe(0);

    expect(metricFor(Metric::Pageviews->value))->toBe(2.0);
});

it('rebuilds every period with --period=all', function (): void {
    seedEntry('2026-03-14 09:00:00');

    Artisan::call('cairn:rollup --date=2026-03-14 --period=all');

    $periods = aggregates()->distinct()->pluck('period')->sort()->values()->all();

    expect($periods)->toBe(['day', 'hour', 'month']);
});

it('refuses an unknown period', function (): void {
    expect(Artisan::call('cairn:rollup --period=fortnight'))->toBe(1);
});

it('refuses a window that ends before it starts', function (): void {
    expect(Artisan::call('cairn:rollup --from=2026-03-15 --to=2026-03-14'))->toBe(1);
});

it('is registered as a console command', function (): void {
    expect(array_keys(Artisan::all()))
        ->toContain('cairn:rollup')
        ->toContain('cairn:prune')
        ->toContain('cairn:work');
});
