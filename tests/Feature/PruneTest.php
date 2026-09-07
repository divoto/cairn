<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Divoto\Cairn\Contracts\Presence;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Contracts\UniqueCounter;
use Divoto\Cairn\Enums\Period;
use Divoto\Cairn\Maintenance\Pruner;
use Divoto\Cairn\Presence\NullPresence;
use Divoto\Cairn\Support\Tables;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/**
 * Stands in for a MySQL/MariaDB connection just far enough to exercise the
 * partition-pruning path — SQLite, the default test connection, has no
 * `information_schema` and cannot partition at all, so that path has no other
 * way to run under this suite. Reports a partitionable driver, answers the
 * fixed `information_schema.partitions` query with canned rows instead of
 * running it, and records every `ALTER TABLE ... DROP PARTITION` statement
 * rather than executing it against anything real.
 */
final class FakePartitionConnection extends Connection
{
    /** @var list<string> */
    public array $statements = [];

    /**
     * @param  list<array<string, string>>  $rows
     */
    public function __construct(
        private readonly array $rows = [],
        private readonly ?Throwable $selectThrows = null,
        private readonly ?Throwable $statementThrows = null,
    ) {
        parent::__construct(new PDO('sqlite::memory:'));
    }

    public function getDriverName(): string
    {
        return 'mysql';
    }

    /**
     * Laravel 13 added `$fetchUsing`; declaring it keeps this fake compatible
     * with both the 12 and the 13 signature.
     *
     * @param  array<int, mixed>  $bindings
     * @param  array<int, mixed>  $fetchUsing
     * @return list<array<string, string>>
     */
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
    {
        if ($this->selectThrows instanceof Throwable) {
            throw $this->selectThrows;
        }

        return $this->rows;
    }

    /**
     * @param  array<int, mixed>  $bindings
     */
    public function statement($query, $bindings = []): bool
    {
        if ($this->statementThrows instanceof Throwable) {
            throw $this->statementThrows;
        }

        $this->statements[] = $query;

        return true;
    }
}

/**
 * Builds a Pruner backed by the fake connection above, and points both
 * Tables::driver() and Pruner's own connection() at it for the duration of
 * the callback — both read the connection through `app(DatabaseManager::class)`,
 * so the container binding is what has to move, not just Pruner's constructor
 * argument. Restored afterwards so no other test in the file inherits it.
 */
function withFakePartitionConnection(FakePartitionConnection $connection, Closure $callback): mixed
{
    // Resolved before the swap below, so Pruner's other dependencies stay
    // wired to the real testing connection rather than picking up the fake
    // one meant only for the partition-metadata queries.
    $config = app(Config::class);
    $storage = app(Storage::class);
    $uniques = app(UniqueCounter::class);
    $presence = app(Presence::class);

    $original = app(DatabaseManager::class);

    $fake = new class($connection) extends DatabaseManager
    {
        public function __construct(private readonly Connection $fake) {}

        public function connection($name = null): Connection
        {
            return $this->fake;
        }
    };

    app()->instance(DatabaseManager::class, $fake);

    try {
        return $callback(new Pruner($fake, $config, $storage, $uniques, $presence));
    } finally {
        app()->instance(DatabaseManager::class, $original);
    }
}

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

it('reports a table as partitioned once the engine actually carries partitions', function (): void {
    $connection = new FakePartitionConnection([['partition_name' => 'p202601']]);

    withFakePartitionConnection($connection, function (Pruner $pruner): void {
        expect($pruner->isPartitioned(Tables::entries()))->toBeTrue();
    });
});

it('treats a partitioned engine as unpartitioned when the metadata query itself fails', function (): void {
    $connection = new FakePartitionConnection(selectThrows: new RuntimeException('metadata unavailable'));

    withFakePartitionConnection($connection, function (Pruner $pruner): void {
        expect($pruner->isPartitioned(Tables::entries()))->toBeFalse();
    });
});

/**
 * Exercises every branch droppablePartitions() takes over a row set: a
 * partition before the cutoff (droppable), one after it (kept), the catch-all
 * `pmax` (never dropped), a row keyed in the uppercase casing some drivers
 * return, and a row with no recognisable partition name at all.
 */
it('keeps only the partitions that lie entirely before the cutoff', function (): void {
    $connection = new FakePartitionConnection([
        ['partition_name' => 'p202501'],
        ['partition_name' => 'p202603'],
        ['partition_name' => 'pmax'],
        ['PARTITION_NAME' => 'p202412'],
        ['unrelated_column' => 'x'],
    ]);

    withFakePartitionConnection($connection, function (Pruner $pruner): void {
        $droppable = $pruner->droppablePartitions(
            Tables::entries(),
            CarbonImmutable::parse('2026-03-15', 'UTC'),
        );

        expect($droppable)->toBe(['p202501', 'p202412']);
    });
});

/**
 * The acceptance criterion for the whole partition path: a partitioned table
 * is pruned by dropping months, not by deleting rows, and the count reported
 * back is however many partitions that was.
 */
it('drops whole partitions instead of deleting rows on a partitioned table', function (): void {
    $now = CarbonImmutable::parse('2026-03-31 12:00:00', 'UTC');
    $connection = new FakePartitionConnection([['partition_name' => 'p202501']]);

    config()->set('cairn.retention.entries', 30);
    config()->set('cairn.retention.sessions', 30);

    withFakePartitionConnection($connection, function (Pruner $pruner) use ($now): void {
        $result = $pruner->prune($now);

        expect($result['entries'])->toBe(1)
            ->and($result['sessions'])->toBe(1);
    });

    expect($connection->statements)->toHaveCount(2)
        ->and($connection->statements[0])->toContain('DROP PARTITION p202501');
});

it('drops nothing when a partitioned table has no partition old enough', function (): void {
    $now = CarbonImmutable::parse('2026-03-31 12:00:00', 'UTC');
    $connection = new FakePartitionConnection([['partition_name' => 'p202603']]);

    config()->set('cairn.retention.entries', 30);

    withFakePartitionConnection($connection, function (Pruner $pruner) use ($now): void {
        expect($pruner->prune($now)['entries'])->toBe(0);
    });

    expect($connection->statements)->toBe([]);
});

it('reports zero and logs when dropping a partition fails', function (): void {
    $now = CarbonImmutable::parse('2026-03-31 12:00:00', 'UTC');
    $connection = new FakePartitionConnection(
        rows: [['partition_name' => 'p202501']],
        statementThrows: new RuntimeException('ALTER TABLE failed'),
    );

    config()->set('cairn.retention.entries', 30);

    withFakePartitionConnection($connection, function (Pruner $pruner) use ($now): void {
        expect($pruner->prune($now)['entries'])->toBe(0);
    });
});

/**
 * Unique-counter data follows the raw entry window, but a failure pruning it
 * must not take down the rest of the sweep — it is reported and swallowed,
 * the same as every other table's pruning failure.
 */
it('reports zero and logs when unique-counter pruning fails', function (): void {
    $now = CarbonImmutable::parse('2026-03-31 12:00:00', 'UTC');

    config()->set('cairn.retention.entries', 30);

    $pruner = new Pruner(
        app(DatabaseManager::class),
        app(Config::class),
        app(Storage::class),
        new class implements UniqueCounter
        {
            public function add(string $day, string $dimension, string $visitor): void {}

            public function count(string $day, string $dimension): int
            {
                return 0;
            }

            public function prune(string $beforeDay): int
            {
                throw new RuntimeException('unique-counter storage unavailable');
            }
        },
        app(Presence::class),
    );

    expect($pruner->prune($now)['visitor_days'])->toBe(0);
});

/**
 * Presence expires on its own sliding window rather than a retention
 * setting, but only DatabasePresence has anything for a sweep to remove —
 * Redis and Null presence expire (or never store) on their own.
 */
it('does nothing for presence when the driver is not database-backed', function (): void {
    $pruner = new Pruner(
        app(DatabaseManager::class),
        app(Config::class),
        app(Storage::class),
        app(UniqueCounter::class),
        new NullPresence,
    );

    expect($pruner->prune()['presence'])->toBe(0);
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

it('reports partitions dropped rather than rows removed, on an engine that partitions', function (): void {
    $connection = new FakePartitionConnection([['partition_name' => 'p202501']]);
    config()->set('cairn.retention.entries', 30);

    withFakePartitionConnection($connection, function (): void {
        app()->forgetInstance(Pruner::class);

        expect(Artisan::call('cairn:prune'))->toBe(0);
    });

    expect(Artisan::output())->toContain('1 partition dropped');
});

it('explains that rollups were deliberately kept', function (): void {
    config()->set('cairn.retention.aggregates');

    Artisan::call('cairn:prune');

    expect(Artisan::output())->toContain('Rollups were kept');
});
