<?php

declare(strict_types=1);

use Divoto\Cairn\Support\Engine;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;

/*
|--------------------------------------------------------------------------
| Cross-engine schema compilation
|--------------------------------------------------------------------------
|
| Cairn supports four database engines, but the default suite runs against
| SQLite and most contributors will never run the others locally. These tests
| compile the schema against each engine's grammar and assert the SQL, without
| opening a connection — so a change that breaks PostgreSQL fails on a laptop
| with only SQLite installed, instead of surfacing in CI an hour later.
|
| The PDO resolver throws deliberately: if compiling ever starts requiring a
| live server, these tests must fail loudly rather than quietly connect.
|
*/

/**
 * A connection for the given engine that can compile SQL but never connect.
 */
function grammarFor(string $driver): Connection
{
    $pdo = static fn (): never => throw new RuntimeException(
        'Schema compilation must not open a database connection.'
    );

    $connection = match ($driver) {
        'mysql' => new MySqlConnection($pdo, 'cairn'),
        'pgsql' => new PostgresConnection($pdo, 'cairn'),
        'sqlite' => new SQLiteConnection($pdo, 'cairn'),
        default => throw new RuntimeException("Unsupported driver {$driver}"),
    };

    $connection->useDefaultSchemaGrammar();

    return $connection;
}

/**
 * Compile the identity-bearing part of `cairn_entries` for an engine.
 */
function compileEntries(string $driver): string
{
    $connection = grammarFor($driver);
    $composite = Engine::usesCompositeTimeKey($driver);

    $blueprint = new Blueprint($connection, 'cairn_entries');
    $blueprint->create();

    if ($composite) {
        $blueprint->unsignedBigInteger('id')->autoIncrement();
    } else {
        $blueprint->bigIncrements('id');
    }

    $blueprint->dateTime('occurred_at');
    $blueprint->binary('visitor', 16, true);
    $blueprint->binary('session', 16, true)->nullable();
    $blueprint->char('country', 2)->nullable();

    if ($composite) {
        $blueprint->primary(['id', 'occurred_at']);
    }

    return implode("\n", $blueprint->toSql());
}

/*
|--------------------------------------------------------------------------
| Binary identity columns
|--------------------------------------------------------------------------
|
| The visitor and session hashes are raw 16-byte values. Each engine spells a
| fixed-length binary column differently, and getting one wrong means either a
| silent re-encoding or a column that cannot hold a null byte.
|
*/

it('emits a fixed-length binary column on MySQL', function (): void {
    expect(compileEntries('mysql'))
        ->toContain('`visitor` binary(16) not null')
        ->toContain('`session` binary(16) null');
});

it('emits bytea on PostgreSQL, which has no fixed-length binary type', function (): void {
    expect(compileEntries('pgsql'))
        ->toContain('"visitor" bytea not null')
        ->toContain('"session" bytea null');
});

it('emits a blob on SQLite', function (): void {
    // SQLite omits an explicit `null` keyword on nullable columns.
    expect(compileEntries('sqlite'))
        ->toContain('"visitor" blob not null')
        ->toContain('"session" blob');
});

it('never stores an identity hash as text on any engine', function (string $driver): void {
    $sql = compileEntries($driver);

    expect($sql)->not->toMatch('/["`]visitor["`]\s+(var)?char/i')
        ->and($sql)->not->toMatch('/["`]visitor["`]\s+text/i');
})->with(['mysql', 'pgsql', 'sqlite']);

/*
|--------------------------------------------------------------------------
| Primary keys
|--------------------------------------------------------------------------
|
| MySQL and MariaDB require every unique key to contain the partitioning
| column, so the raw tables carry (id, occurred_at) from creation — adding it
| later would mean rebuilding a table with hundreds of millions of rows.
|
| This is also the subtle bit: MySQL's grammar omits the inline "primary key"
| on an auto-increment column only when an explicit primary command is present.
| If that behaviour ever changed, the compiled DDL would declare two primary
| keys and creation would fail on the one engine Cairn most needs to work on.
|
*/

it('gives MySQL a composite primary key including the partitioning column', function (): void {
    $sql = compileEntries('mysql');

    expect($sql)->toContain('primary key (`id`, `occurred_at`)')
        ->and($sql)->toContain('auto_increment')
        // Exactly one primary key declaration, not an inline one as well.
        ->and(substr_count($sql, 'primary key'))->toBe(1);
});

it('gives PostgreSQL and SQLite a plain identity key', function (string $driver): void {
    $sql = compileEntries($driver);

    // The time column must appear as a column, but never inside the key —
    // the composite key costs index width and buys nothing off MySQL.
    expect($sql)->toContain('occurred_at')
        ->and($sql)->not->toMatch('/primary key \([^)]*occurred_at/i')
        ->and(substr_count(strtolower($sql), 'primary key'))->toBe(1);
})->with(['pgsql', 'sqlite']);

it('agrees with Engine about which drivers get a composite key', function (): void {
    expect(Engine::usesCompositeTimeKey('mysql'))->toBeTrue()
        ->and(Engine::usesCompositeTimeKey('mariadb'))->toBeTrue()
        ->and(Engine::usesCompositeTimeKey('pgsql'))->toBeFalse()
        ->and(Engine::usesCompositeTimeKey('sqlite'))->toBeFalse();
});

it('only claims partitioning support where the ALTER syntax is valid', function (): void {
    expect(Engine::supportsPartitioning('mysql'))->toBeTrue()
        ->and(Engine::supportsPartitioning('mariadb'))->toBeTrue()
        ->and(Engine::supportsPartitioning('pgsql'))->toBeFalse()
        ->and(Engine::supportsPartitioning('sqlite'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The invariant, at compile time
|--------------------------------------------------------------------------
*/

it('compiles no address-typed column on any engine', function (string $driver): void {
    $sql = strtolower(compileEntries($driver));

    expect($sql)->not->toContain(' inet')
        ->and($sql)->not->toContain(' cidr')
        ->and($sql)->not->toContain('ip_address')
        ->and($sql)->not->toContain('remote_addr');
})->with(['mysql', 'pgsql', 'sqlite']);
