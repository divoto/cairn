<?php

declare(strict_types=1);

use Divoto\Cairn\Support\Engine;
use Divoto\Cairn\Support\Tables;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// Datasets are built at collection time, before the testbench application
// exists, so they carry unprefixed names and the prefix is applied inside the
// test where config is available.
it('creates every Cairn table', function (string $table): void {
    expect(Schema::connection(Tables::connection())->hasTable(Tables::name($table)))->toBeTrue();
})->with(['entries', 'sessions', 'aggregates', 'visitor_days', 'presence']);

/*
|--------------------------------------------------------------------------
| The invariant
|--------------------------------------------------------------------------
|
| CLAUDE.md: raw IP addresses are never persisted. This is the acceptance
| criterion for Phase 2 — no column in any Cairn table is named or typed to
| hold an address. It runs against whatever engine the suite is pointed at, so
| a column added on one engine's code path cannot slip through.
|
*/

it('has no column in any table that could hold an IP address', function (): void {
    $forbidden = [
        'ip', 'ip_address', 'ipaddress', 'ipv4', 'ipv6', 'remote_addr',
        'remoteaddr', 'client_ip', 'clientip', 'address', 'inet', 'host_ip',
    ];

    foreach (Tables::all() as $table) {
        foreach (Schema::connection(Tables::connection())->getColumnListing($table) as $column) {
            expect($forbidden)->not->toContain(
                strtolower($column),
                "{$table}.{$column} looks like it holds an IP address"
            );
        }
    }
});

/**
 * PostgreSQL has dedicated `inet` and `cidr` types. A column of either would
 * hold an address whatever it was named, so the type is checked as well as the
 * name.
 */
it('uses no address-typed column on any engine', function (): void {
    $connection = Schema::connection(Tables::connection());

    foreach (Tables::all() as $table) {
        foreach ($connection->getColumns($table) as $column) {
            expect(strtolower((string) $column['type_name']))
                ->not->toBeIn(['inet', 'cidr', 'macaddr']);
        }
    }
});

it('does not store a user agent anywhere', function (): void {
    foreach (Tables::all() as $table) {
        foreach (Schema::connection(Tables::connection())->getColumnListing($table) as $column) {
            expect(strtolower($column))->not->toContain('user_agent')
                ->and(strtolower($column))->not->toContain('useragent');
        }
    }
});

/*
|--------------------------------------------------------------------------
| Column shape
|--------------------------------------------------------------------------
*/

it('gives cairn_entries every documented column', function (): void {
    $columns = Schema::connection(Tables::connection())->getColumnListing(Tables::entries());

    expect($columns)->toContain(
        'id', 'occurred_at', 'type', 'name', 'visitor', 'session', 'route', 'url',
        'referrer_host', 'channel',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'country', 'region', 'city',
        'device_type', 'browser', 'os', 'screen_class', 'language',
        'status', 'duration_ms', 'time_on_page', 'scroll_depth',
        'value', 'properties', 'subject_type', 'subject_id', 'user_id', 'tenant_id',
    );
});

it('gives cairn_sessions its acquisition and outcome columns', function (): void {
    $columns = Schema::connection(Tables::connection())->getColumnListing(Tables::sessions());

    expect($columns)->toContain(
        'id', 'visitor', 'started_at', 'last_activity_at', 'ended_at',
        'page_count', 'duration_seconds', 'entry_url', 'exit_url', 'is_bounce',
        'referrer_host', 'channel',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'country', 'device_type', 'tenant_id',
    );
});

it('gives cairn_aggregates its rollup columns', function (): void {
    $columns = Schema::connection(Tables::connection())->getColumnListing(Tables::aggregates());

    expect($columns)->toContain(
        'bucket', 'period', 'type', 'aggregate', 'key', 'key_hash', 'value', 'tenant_id',
    );
});

it('gives the driver-specific tables exactly what they need', function (): void {
    $connection = Schema::connection(Tables::connection());

    expect($connection->getColumnListing(Tables::visitorDays()))
        ->toContain('day', 'dimension_hash', 'visitor', 'tenant_id');

    expect($connection->getColumnListing(Tables::presence()))
        ->toContain('visitor', 'page', 'last_seen_at', 'tenant_id');
});

/*
|--------------------------------------------------------------------------
| Tenancy
|--------------------------------------------------------------------------
|
| tenant_id is NOT NULL everywhere, for two reasons that both end in silent
| wrong numbers:
|
| - Every supported engine treats NULLs as distinct inside a unique index, so
|   a nullable column in one of those keys would permit duplicate aggregate
|   rows, and a single-tenant installation would double-count everything.
| - Tenant scoping is applied as a WHERE comparison on every read and write.
|   A NULL matches nothing, so a single-tenant installation would report zero.
|
*/

it('never allows a null tenant on any table', function (string $table): void {
    $table = Tables::name($table);

    $columns = collect(Schema::connection(Tables::connection())->getColumns($table))
        ->keyBy('name');

    expect($columns)->toHaveKey('tenant_id');

    $tenant = $columns->get('tenant_id', []);

    expect($tenant['nullable'] ?? true)->toBeFalse(
        "{$table}.tenant_id must be NOT NULL: it sits inside a unique key, ".
        'and every supported engine treats NULLs there as distinct.'
    );
})->with(['entries', 'sessions', 'aggregates', 'visitor_days', 'presence']);

it('rejects a duplicate aggregate row for an untenanted installation', function (): void {
    $connection = Schema::connection(Tables::connection())->getConnection();

    $row = [
        'bucket' => 1767225600,
        'period' => 'day',
        'type' => 'pageviews',
        'aggregate' => 'sum',
        'key' => '[]',
        'key_hash' => binaryColumn(random_bytes(16)),
        'value' => 10,
        'tenant_id' => '',
    ];

    $connection->table(Tables::aggregates())->insert($row);

    expect(fn () => $connection->table(Tables::aggregates())->insert($row))
        ->toThrow(QueryException::class);
});

it('counts a visitor once per day per dimension however often they are added', function (): void {
    $connection = Schema::connection(Tables::connection())->getConnection();

    $row = [
        'day' => '2026-01-01',
        'dimension_hash' => binaryColumn(random_bytes(16)),
        'visitor' => binaryColumn(random_bytes(16)),
        'tenant_id' => '',
    ];

    $connection->table(Tables::visitorDays())->insertOrIgnore($row);
    $connection->table(Tables::visitorDays())->insertOrIgnore($row);
    $connection->table(Tables::visitorDays())->insertOrIgnore($row);

    expect($connection->table(Tables::visitorDays())->count())->toBe(1);
});

it('keeps one presence row per visitor per tenant', function (): void {
    $connection = Schema::connection(Tables::connection())->getConnection();
    $visitor = random_bytes(16);

    foreach (['/one', '/two', '/three'] as $page) {
        $connection->table(Tables::presence())->upsert(
            [['visitor' => binaryColumn($visitor), 'page' => $page, 'last_seen_at' => '2026-01-01 00:00:00', 'tenant_id' => '']],
            ['visitor', 'tenant_id'],
            ['page', 'last_seen_at'],
        );
    }

    expect($connection->table(Tables::presence())->count())->toBe(1)
        ->and($connection->table(Tables::presence())->value('page'))->toBe('/three');
});

/*
|--------------------------------------------------------------------------
| Binary identity columns
|--------------------------------------------------------------------------
*/

it('round-trips a raw 16-byte hash without mangling it', function (): void {
    $connection = Schema::connection(Tables::connection())->getConnection();

    // Deliberately includes a null byte and high bytes: a column that is
    // secretly text would truncate or re-encode this.
    $visitor = "\x00\xff\x01\xfe".random_bytes(12);

    $connection->table(Tables::entries())->insert([
        'occurred_at' => '2026-01-01 12:00:00',
        'type' => 'pageview',
        'visitor' => binaryColumn($visitor),
    ]);

    $stored = $connection->table(Tables::entries())->value('visitor');

    expect(binaryValue($stored))->toBe($visitor);
});

/*
|--------------------------------------------------------------------------
| Engine-specific structure
|--------------------------------------------------------------------------
*/

it('puts the time column in the primary key only where partitioning needs it', function (): void {
    $driver = Tables::driver();
    $primary = collect(Schema::connection(Tables::connection())->getIndexes(Tables::entries()))
        ->firstWhere('primary', true) ?? ['columns' => []];

    expect($primary['columns'])->toBe(
        Engine::usesCompositeTimeKey($driver) ? ['id', 'occurred_at'] : ['id']
    );
});

it('rolls back cleanly', function (): void {
    expect(Artisan::call('migrate:rollback'))->toBe(0);

    foreach (Tables::all() as $table) {
        expect(Schema::connection(Tables::connection())->hasTable($table))->toBeFalse();
    }
});
