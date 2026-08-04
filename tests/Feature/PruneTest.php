<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Divoto\Cairn\Contracts\UniqueCounter;
use Divoto\Cairn\Enums\Period;
use Divoto\Cairn\Maintenance\Pruner;
use Divoto\Cairn\Support\Tables;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

function table(string $name): Builder
{
    return app(DatabaseManager::class)
        ->connection(Tables::connection())
        ->table(Tables::name($name));
}

function seedRawEntry(string $at): void
{
    table('entries')->insert([
        'occurred_at' => $at,
        'type' => 'pageview',
        'visitor' => binaryColumn(random_bytes(16)),
        'tenant_id' => '',
    ]);
}

function seedSession(string $startedAt): void
{
    table('sessions')->insert([
        'id' => binaryColumn(random_bytes(16)),
        'visitor' => binaryColumn(random_bytes(16)),
        'started_at' => $startedAt,
        'last_activity_at' => $startedAt,
        'page_count' => 1,
        'duration_seconds' => 0,
        'is_bounce' => true,
        'tenant_id' => '',
    ]);
}

/*
|--------------------------------------------------------------------------
| Retention
|--------------------------------------------------------------------------
*/

it('removes raw entries past the retention window and keeps the rest', function (): void {
    $now = CarbonImmutable::parse('2026-03-31 12:00:00', 'UTC');

    seedRawEntry('2026-01-01 09:00:00');   // 89 days old
    seedRawEntry('2026-02-20 09:00:00');   // 39 days old
    seedRawEntry('2026-03-20 09:00:00');   // 11 days old
    seedRawEntry('2026-03-30 09:00:00');   // 1 day old

    config()->set('cairn.retention.entries', 30);

    $removed = app(Pruner::class)->prune($now);

    expect($removed['entries'])->toBe(2)
        ->and(table('entries')->count())->toBe(2);
});

it('removes sessions past their own retention window', function (): void {
    $now = CarbonImmutable::parse('2026-03-31 12:00:00', 'UTC');

    seedSession('2026-01-01 09:00:00');
    seedSession('2026-03-30 09:00:00');

    config()->set('cairn.retention.sessions', 30);

    expect(app(Pruner::class)->prune($now)['sessions'])->toBe(1)
        ->and(table('sessions')->count())->toBe(1);
});

/**
 * Unique-counter rows are visitor-level data — a hash per person per day — so
 * they cannot outlive the entries they were derived from.
 */
it('removes unique-counter data on the raw entry window', function (): void {
    $now = CarbonImmutable::parse('2026-03-31 12:00:00', 'UTC');

    app(UniqueCounter::class)->add('2026-01-01', 'overall', random_bytes(16));
    app(UniqueCounter::class)->add('2026-03-30', 'overall', random_bytes(16));

    config()->set('cairn.retention.entries', 30);

    app(Pruner::class)->prune($now);

    expect(app(UniqueCounter::class)->count('2026-01-01', 'overall'))->toBe(0)
        ->and(app(UniqueCounter::class)->count('2026-03-30', 'overall'))->toBe(1);
});

/**
 * Rollups are the permanent record. They hold counts rather than records of
 * people, so nothing removes them unless the deployer asks.
 */
it('never removes rollups by default', function (): void {
    $now = CarbonImmutable::parse('2026-03-31 12:00:00', 'UTC');

    app(DatabaseManager::class)->connection(Tables::connection())->table(Tables::aggregates())->insert([
        'bucket' => CarbonImmutable::parse('2020-01-01', 'UTC')->getTimestamp(),
        'period' => Period::Day->value,
        'type' => 'pageviews',
        'aggregate' => 'overall',
        'key' => '[]',
        'key_hash' => binaryColumn(random_bytes(16)),
        'value' => 100,
        'tenant_id' => '',
    ]);

    config()->set('cairn.retention.aggregates');

    expect(app(Pruner::class)->prune($now)['aggregates'])->toBe(0)
        ->and(table('aggregates')->count())->toBe(1);
});

it('removes rollups only when retention is explicitly configured', function (): void {
    $now = CarbonImmutable::parse('2026-03-31 12:00:00', 'UTC');

    app(DatabaseManager::class)->connection(Tables::connection())->table(Tables::aggregates())->insert([
        'bucket' => CarbonImmutable::parse('2020-01-01', 'UTC')->getTimestamp(),
        'period' => Period::Day->value,
        'type' => 'pageviews',
        'aggregate' => 'overall',
        'key' => '[]',
        'key_hash' => binaryColumn(random_bytes(16)),
        'value' => 100,
        'tenant_id' => '',
    ]);

    config()->set('cairn.retention.aggregates', 365);

    expect(app(Pruner::class)->prune($now)['aggregates'])->toBe(1)
        ->and(table('aggregates')->count())->toBe(0);
});

it('keeps everything when retention is null', function (): void {
    $now = CarbonImmutable::parse('2026-03-31 12:00:00', 'UTC');

    seedRawEntry('2020-01-01 09:00:00');
    seedSession('2020-01-01 09:00:00');

    config()->set('cairn.retention.entries');
    config()->set('cairn.retention.sessions');

    app(Pruner::class)->prune($now);

    expect(table('entries')->count())->toBe(1)
        ->and(table('sessions')->count())->toBe(1);
});

it('removes stale presence rows', function (): void {
    table('presence')->insert([
        'visitor' => binaryColumn(random_bytes(16)),
        'page' => '/old',
        'last_seen_at' => CarbonImmutable::now('UTC')->subHour()->toDateTimeString(),
        'tenant_id' => '',
    ]);

    table('presence')->insert([
        'visitor' => binaryColumn(random_bytes(16)),
        'page' => '/now',
        'last_seen_at' => CarbonImmutable::now('UTC')->toDateTimeString(),
        'tenant_id' => '',
    ]);

    expect(app(Pruner::class)->prune()['presence'])->toBe(1)
        ->and(table('presence')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Partitions
|--------------------------------------------------------------------------
|
| Where a table is partitioned, whole months are dropped as a metadata
| operation. SQLite cannot partition, so it takes the chunked-delete path —
| which is what these assertions cover on the default suite. CI runs the same
| tests against MySQL, where the partition path is live.
|
*/

it('falls back to deleting rows when a table is not partitioned', function (): void {
    $now = CarbonImmutable::parse('2026-03-31 12:00:00', 'UTC');

    seedRawEntry('2026-01-01 09:00:00');
    config()->set('cairn.retention.entries', 30);

    expect(app(Pruner::class)->isPartitioned(Tables::entries()))->toBeFalse()
        ->and(app(Pruner::class)->prune($now)['entries'])->toBe(1);
});

it('reports no droppable partitions on an unpartitioned table', function (): void {
    expect(app(Pruner::class)->droppablePartitions(Tables::entries(), CarbonImmutable::now('UTC')))
        ->toBe([]);
});

/*
|--------------------------------------------------------------------------
| The command
|--------------------------------------------------------------------------
*/

it('prunes through the console command', function (): void {
    seedRawEntry(CarbonImmutable::now('UTC')->subDays(90)->toDateTimeString());
    seedRawEntry(CarbonImmutable::now('UTC')->toDateTimeString());

    config()->set('cairn.retention.entries', 30);

    expect(Artisan::call('cairn:prune'))->toBe(0)
        ->and(table('entries')->count())->toBe(1);
});

it('explains that rollups were deliberately kept', function (): void {
    config()->set('cairn.retention.aggregates');

    Artisan::call('cairn:prune');

    expect(Artisan::output())->toContain('Rollups were kept');
});
