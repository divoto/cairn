<?php

declare(strict_types=1);

use Divoto\Cairn\Data\Entry;
use Divoto\Cairn\Enums\EntryType;
use Divoto\Cairn\Tests\TestCase;
use Pest\Support\HigherOrderTapProxy;

/*
|--------------------------------------------------------------------------
| Test case binding
|--------------------------------------------------------------------------
|
| Every test under tests/ runs against the testbench application defined in
| TestCase. ArchTest.php needs no application, but binding it here is
| harmless and keeps the configuration to a single line.
|
*/

pest()->extend(TestCase::class)->in(__DIR__);

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
    if (is_resource($value)) {
        return (string) stream_get_contents($value);
    }

    return is_string($value) ? $value : '';
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
