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
