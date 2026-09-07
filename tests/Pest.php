<?php

declare(strict_types=1);

use Divoto\Cairn\Data\Entry;
use Divoto\Cairn\Data\ReportRow;
use Divoto\Cairn\Enums\EntryType;
use Divoto\Cairn\Support\Binary;
use Divoto\Cairn\Support\Tables;
use Divoto\Cairn\Tests\StandaloneTestCase;
use Divoto\Cairn\Tests\TestCase;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Pest\Support\HigherOrderTapProxy;

/*
|--------------------------------------------------------------------------
| Test case binding
|--------------------------------------------------------------------------
|
| Feature and Unit run against the testbench application defined in TestCase,
| which registers Livewire and Pulse so the optional adapters are tested
| rather than shipped blind.
|
| Deployment runs against StandaloneTestCase — Cairn and nothing else — so it
| can prove the package survives `view:cache` in an application that installed
| none of them. It cannot share a directory with the others: Pest binds one
| test case per path, and an application with every optional package present
| cannot fail the way a real installation does.
|
| ArchTest.php is bound to neither. Arch tests assert on source, not on a
| booted application, and never needed one.
|
*/

pest()->extend(TestCase::class)->in('Feature', 'Unit');
pest()->extend(StandaloneTestCase::class)->in('Deployment');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * The running test case, typed.
 *
 * Pest's `test()` hands back a HigherOrderTapProxy wrapping the case, and is
 * declared as returning PHPUnit's TestCase — neither of which carries
 * Laravel's HTTP testing helpers. Unwrapping and narrowing once here keeps
 * every HTTP test analysable without per-call annotations.
 */
function cairnTest(): TestCase
{
    $case = test();

    if ($case instanceof HigherOrderTapProxy) {
        $case = $case->target;
    }

    if (! $case instanceof TestCase) {
        throw new RuntimeException('Not running inside a Cairn test case: got '.get_debug_type($case));
    }

    return $case;
}

/**
 * Read a binary column back as a PHP string.
 *
 * PostgreSQL hands `bytea` back as a stream resource while MySQL, MariaDB and
 * SQLite return a string. A test that assumed either would pass on three
 * engines and fail on the fourth.
 */
function binaryValue(mixed $value): string
{
    return Binary::read($value);
}

/**
 * Prepare a raw hash to be written to a binary column by a fixture.
 *
 * Fixtures write these columns with the query builder directly, so they need
 * the same handling the package's own writes get — see {@see Binary}. Without
 * it a fixture inserts happily on three engines and fails on PostgreSQL,
 * which is exactly the failure mode this suite exists to catch.
 */
function binaryColumn(string $value): string|ExpressionContract
{
    $connection = app(DatabaseManager::class)->connection(Tables::connection());

    return Binary::bind($connection, $value) ?? $value;
}

/**
 * The nth row of a report, asserted to exist.
 *
 * Collection offsets are nullable to a static analyser, and a test that
 * silently skipped its assertions on a null row would be worse than one that
 * failed loudly.
 *
 * @param  Collection<int, ReportRow>  $rows
 */
function row(Collection $rows, int $index = 0): ReportRow
{
    $row = $rows->get($index);

    if (! $row instanceof ReportRow) {
        throw new RuntimeException("Report has no row at index {$index}.");
    }

    return $row;
}

/**
 * Read a query-builder value as an int.
 *
 * Query results are `mixed`, and different engines hand back different scalar
 * types for the same column — SQLite returns an int where MySQL returns a
 * numeric string.
 */
function columnInt(mixed $value): int
{
    return is_numeric($value) ? (int) $value : 0;
}

/**
 * Read a query-builder value as a float.
 *
 * Decimal columns come back as a string on some engines and a float on
 * others, so neither a cast nor a string helper alone is enough.
 */
function columnFloat(mixed $value): float
{
    return is_numeric($value) ? (float) $value : 0.0;
}

/**
 * Read a value that should be a string, without asserting it is one.
 */
function asString(mixed $value): string
{
    return is_string($value) ? $value : '';
}

/**
 * Build an entry with sensible defaults, overriding only what a test cares
 * about.
 *
 * Note the visitor hash is 16 random bytes, matching what VisitorHasher
 * produces — tests must never assume it is printable, because the column is
 * binary and a test that passes on a hex string would hide an encoding bug.
 *
 * @param  array<string, scalar|null>|null  $properties
 */
function anEntry(
    ?EntryType $type = null,
    ?string $visitor = null,
    ?string $route = null,
    ?string $url = null,
    ?string $name = null,
    ?array $properties = null,
): Entry {
    return new Entry(
        occurredAt: now()->toImmutable(),
        type: $type ?? EntryType::Pageview,
        visitor: $visitor ?? random_bytes(16),
        name: $name,
        route: $route,
        url: $url,
        properties: $properties,
    );
}

/**
 * Make every Cairn table unreachable for the rest of the test.
 *
 * The failure-containment tests need storage to be gone. Dropping a table is
 * the literal way, but DROP TABLE is DDL, and MySQL and MariaDB commit the
 * enclosing transaction implicitly when they run it — which takes
 * RefreshDatabase's savepoints with it and fails every later test in the file
 * with "SAVEPOINT does not exist". Pointing the prefix at tables that were
 * never created fails every query the same way, on every engine, without
 * touching the schema.
 */
function cairnStorageGone(): void
{
    config()->set('cairn.table_prefix', 'gone_');
}
