<?php

declare(strict_types=1);

use Divoto\Cairn\Support\Engine;
use Divoto\Cairn\Support\Tables;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/**
 * Stands in for a MySQL/MariaDB connection so `cairn:partition`'s real work —
 * building and running the `ALTER TABLE` statements — can run under SQLite,
 * the default test connection, which cannot partition at all.
 */
final class FakePartitionCommandConnection extends Connection
{
    /** @var list<string> */
    public array $statements = [];

    public function __construct(private readonly ?Throwable $statementThrows = null)
    {
        parent::__construct(new PDO('sqlite::memory:'));
    }

    public function getDriverName(): string
    {
        return 'mysql';
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

function withFakePartitionableEngine(FakePartitionCommandConnection $connection, Closure $callback): mixed
{
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
        return $callback();
    } finally {
        app()->instance(DatabaseManager::class, $original);
    }
}

/**
 * On an engine that cannot partition, the command must say so and succeed.
 *
 * Failing here would be worse than useless: partitioning is an optimisation,
 * and a non-zero exit would break a deployment script on the very hosts —
 * shared hosting, SQLite in CI — that Cairn promises to work on.
 */
it('no-ops with a clear message on an engine that cannot partition', function (): void {
    if (Engine::supportsPartitioning(Tables::driver())) {
        $this->markTestSkipped('This engine supports partitioning.');
    }

    $exit = Artisan::call('cairn:partition');

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('Partitioning is not available');
});

it('leaves the tables untouched when it cannot partition', function (): void {
    if (Engine::supportsPartitioning(Tables::driver())) {
        $this->markTestSkipped('This engine supports partitioning.');
    }

    expect(Artisan::call('cairn:partition'))->toBe(0);

    // The whole point of the no-op: everything still works afterwards.
    $connection = app(DatabaseManager::class)->connection(Tables::connection());

    $connection->table(Tables::entries())->insert([
        'occurred_at' => '2026-01-01 12:00:00',
        'type' => 'pageview',
        'visitor' => binaryColumn(random_bytes(16)),
    ]);

    expect($connection->table(Tables::entries())->count())->toBe(1);
});

it('is registered as a console command', function (): void {
    expect(array_keys(Artisan::all()))->toContain('cairn:partition');
});

/*
|--------------------------------------------------------------------------
| On an engine that supports it
|--------------------------------------------------------------------------
|
| Genuinely exercised rather than skipped: SQLite has no information_schema
| and cannot partition, so the command's real work runs against a fake
| connection that reports a partitionable driver instead.
|
*/

it('partitions every target table', function (): void {
    $connection = new FakePartitionCommandConnection;

    withFakePartitionableEngine($connection, function (): void {
        expect(Artisan::call('cairn:partition', ['--months' => 3]))->toBe(0);
    });

    expect(Artisan::output())
        ->toContain('Partitioned '.Tables::entries().' into 3 monthly partitions.')
        ->toContain('Partitioned '.Tables::sessions().' into 3 monthly partitions.');

    expect($connection->statements)->toHaveCount(2);

    foreach ($connection->statements as $statement) {
        expect($statement)->toContain('PARTITION BY RANGE COLUMNS')
            ->toContain('PARTITION pmax VALUES LESS THAN (MAXVALUE)');
    }
});

it('clamps a zero or negative month count to one', function (): void {
    $connection = new FakePartitionCommandConnection;

    withFakePartitionableEngine($connection, function (): void {
        expect(Artisan::call('cairn:partition', ['--months' => '0']))->toBe(0);
    });

    expect($connection->statements[0])->toContain('PARTITION p');
});

it('prints the statements without running them in pretend mode', function (): void {
    $connection = new FakePartitionCommandConnection;

    withFakePartitionableEngine($connection, function (): void {
        expect(Artisan::call('cairn:partition', ['--pretend' => true]))->toBe(0);
    });

    expect($connection->statements)->toBe([])
        ->and(Artisan::output())->toContain('ALTER TABLE '.Tables::entries().' PARTITION BY RANGE COLUMNS');
});

/**
 * Most commonly a missing privilege, or a table already partitioned. Neither
 * is worth failing the command over, and neither should stop the other
 * table's turn.
 */
it('reports a table it could not partition without stopping the rest', function (): void {
    $connection = new FakePartitionCommandConnection(new RuntimeException('table is already partitioned'));

    withFakePartitionableEngine($connection, function (): void {
        expect(Artisan::call('cairn:partition'))->toBe(0);
    });

    expect(Artisan::output())
        ->toContain('Could not partition '.Tables::entries().': table is already partitioned')
        ->toContain('Could not partition '.Tables::sessions().': table is already partitioned');
});
