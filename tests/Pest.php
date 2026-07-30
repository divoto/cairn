<?php

declare(strict_types=1);

use Divoto\Cairn\Data\Entry;
use Divoto\Cairn\Enums\EntryType;
use Divoto\Cairn\Tests\TestCase;

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
