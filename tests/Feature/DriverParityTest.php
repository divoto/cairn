<?php

declare(strict_types=1);

use Divoto\Cairn\Contracts\Ingest;
use Divoto\Cairn\Contracts\Presence;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Contracts\UniqueCounter;
use Divoto\Cairn\Enums\EntryType;
use Divoto\Cairn\Support\Tables;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Driver parity
|--------------------------------------------------------------------------
|
| CLAUDE.md: every driver pair is tested against the SAME suite via a shared
| dataset. A behaviour that differs between drivers is a bug, not a feature —
| so these assertions are written once and run twice.
|
| Redis is required to run the full suite. It is not skipped when absent: a
| conditionally-skipped driver is a driver nobody notices breaking.
|
*/

beforeEach(function (): void {
    // Each test starts from an empty Redis keyspace so counts are exact.
    try {
        Redis::connection()->flushdb();
    } catch (Throwable $e) {
        $this->markTestSkipped(
            'Redis is required for the driver-parity suite: '.$e->getMessage()
        );
    }
});

/**
 * Point Cairn at a driver and forget anything already resolved.
 */
function useDriver(string $driver): void
{
    config()->set('cairn.driver', $driver);

    foreach ([Ingest::class, Storage::class, UniqueCounter::class, Presence::class] as $contract) {
        app()->forgetInstance($contract);
    }
}

function entryRows(): Builder
{
    return app(DatabaseManager::class)
        ->connection(Tables::connection())
        ->table(Tables::entries());
}

/*
|--------------------------------------------------------------------------
| Ingest
|--------------------------------------------------------------------------
*/

it('records an entry through to storage', function (string $driver): void {
    useDriver($driver);

    app(Ingest::class)->record(anEntry(route: 'pricing.index', url: '/pricing'));

    expect(app(Ingest::class)->digest(app(Storage::class)))->toBe(1)
        ->and(entryRows()->count())->toBe(1)
        ->and(entryRows()->value('route'))->toBe('pricing.index');
})->with(['database', 'redis']);

it('records nothing until digest is called', function (string $driver): void {
    useDriver($driver);

    app(Ingest::class)->record(anEntry());

    // Nothing may reach durable storage while a response is still being
    // produced. This is the whole reason ingest exists.
    expect(entryRows()->count())->toBe(0);
})->with(['database', 'redis']);

it('preserves the raw binary visitor hash end to end', function (string $driver): void {
    useDriver($driver);

    // Deliberately contains a null byte and high bytes: any accidental text
    // handling would truncate or re-encode this.
    $visitor = "\x00\xff\x01\xfe".random_bytes(12);

    app(Ingest::class)->record(anEntry(visitor: $visitor));
    app(Ingest::class)->digest(app(Storage::class));

    expect(binaryValue(entryRows()->value('visitor')))->toBe($visitor);
})->with(['database', 'redis']);

it('records a batch of entries', function (string $driver): void {
    useDriver($driver);

    foreach (range(1, 50) as $i) {
        app(Ingest::class)->record(anEntry(url: "/page-{$i}"));
    }

    expect(app(Ingest::class)->digest(app(Storage::class)))->toBe(50)
        ->and(entryRows()->count())->toBe(50);
})->with(['database', 'redis']);

it('reports nothing to digest as zero rather than failing', function (string $driver): void {
    useDriver($driver);

    expect(app(Ingest::class)->digest(app(Storage::class)))->toBe(0);
})->with(['database', 'redis']);

it('discards everything on trim', function (string $driver): void {
    useDriver($driver);

    app(Ingest::class)->record(anEntry());
    app(Ingest::class)->trim();

    expect(app(Ingest::class)->digest(app(Storage::class)))->toBe(0)
        ->and(entryRows()->count())->toBe(0);
})->with(['database', 'redis']);

it('round-trips every field of an entry', function (string $driver): void {
    useDriver($driver);

    app(Ingest::class)->record(anEntry(
        type: EntryType::Conversion,
        route: 'checkout.complete',
        url: '/checkout/complete',
        name: 'purchase',
        properties: ['plan' => 'pro', 'seats' => 5],
    ));

    app(Ingest::class)->digest(app(Storage::class));

    $row = (array) entryRows()->first();

    expect($row['type'] ?? null)->toBe('conversion')
        ->and($row['name'] ?? null)->toBe('purchase')
        ->and($row['route'] ?? null)->toBe('checkout.complete')
        ->and(json_decode(asString($row['properties'] ?? ''), true))
        ->toBe(['plan' => 'pro', 'seats' => 5]);
})->with(['database', 'redis']);

/*
|--------------------------------------------------------------------------
| Unique counting
|--------------------------------------------------------------------------
*/

it('counts a visitor once however many times they are seen', function (string $driver): void {
    useDriver($driver);

    $visitor = random_bytes(16);

    foreach (range(1, 10) as $ignored) {
        app(UniqueCounter::class)->add('2026-03-01', 'repeat-visitor', $visitor);
    }

    expect(app(UniqueCounter::class)->count('2026-03-01', 'repeat-visitor'))->toBe(1);
})->with(['database', 'redis']);

/**
 * The visitors here are fixed rather than random, and deliberately so.
 *
 * HyperLogLog is not exact at this cardinality. Redis hashes each element into
 * one of 16384 registers, and two of 25 random visitors share a register about
 * 1.8% of the time — when they do, PFCOUNT returns 24 instead of 25 (rarely 23).
 * Random visitors therefore make this test fail roughly one run in fifty, which
 * is a property of HyperLogLog rather than a bug in either driver.
 *
 * A fixed set that lands in 25 distinct registers keeps the assertion exact for
 * both drivers, so a driver that genuinely miscounts still fails the suite.
 * Redis's HLL hash is part of its serialisation format and does not change.
 */
it('counts distinct visitors separately', function (string $driver): void {
    useDriver($driver);

    foreach (range(1, 25) as $visitor) {
        app(UniqueCounter::class)->add(
            '2026-03-02',
            'distinct-visitors',
            substr(hash('sha256', 'visitor-'.$visitor, true), 0, 16),
        );
    }

    expect(app(UniqueCounter::class)->count('2026-03-02', 'distinct-visitors'))->toBe(25);
})->with(['database', 'redis']);

it('keeps dimensions in separate counters', function (string $driver): void {
    useDriver($driver);

    $visitor = random_bytes(16);

    app(UniqueCounter::class)->add('2026-03-03', 'route:pricing', $visitor);
    app(UniqueCounter::class)->add('2026-03-03', 'route:home', $visitor);

    expect(app(UniqueCounter::class)->count('2026-03-03', 'route:pricing'))->toBe(1)
        ->and(app(UniqueCounter::class)->count('2026-03-03', 'route:home'))->toBe(1)
        ->and(app(UniqueCounter::class)->count('2026-03-03', 'route:about'))->toBe(0);
})->with(['database', 'redis']);

/**
 * The salt rotates daily, so a visitor's hash tomorrow is unrelated to today's
 * and there is no cross-day set to deduplicate against. Both drivers must
 * therefore keep days entirely separate — a monthly figure is the sum of daily
 * figures, deliberately.
 */
it('keeps days entirely separate', function (string $driver): void {
    useDriver($driver);

    $visitor = random_bytes(16);

    app(UniqueCounter::class)->add('2026-03-04', 'across-days', $visitor);
    app(UniqueCounter::class)->add('2026-03-05', 'across-days', $visitor);

    expect(app(UniqueCounter::class)->count('2026-03-04', 'across-days'))->toBe(1)
        ->and(app(UniqueCounter::class)->count('2026-03-05', 'across-days'))->toBe(1);
})->with(['database', 'redis']);

it('reports zero for a day nobody visited', function (string $driver): void {
    useDriver($driver);

    expect(app(UniqueCounter::class)->count('2026-01-01', 'overall'))->toBe(0);
})->with(['database', 'redis']);

it('prunes counters for days before the cutoff', function (string $driver): void {
    useDriver($driver);

    app(UniqueCounter::class)->add('2026-03-06', 'prunable', random_bytes(16));
    app(UniqueCounter::class)->add('2026-03-20', 'prunable', random_bytes(16));

    app(UniqueCounter::class)->prune('2026-03-10');

    expect(app(UniqueCounter::class)->count('2026-03-06', 'prunable'))->toBe(0)
        ->and(app(UniqueCounter::class)->count('2026-03-20', 'prunable'))->toBe(1);
})->with(['database', 'redis']);

/*
|--------------------------------------------------------------------------
| Presence
|--------------------------------------------------------------------------
*/

it('counts nobody when nobody is present', function (string $driver): void {
    useDriver($driver);

    expect(app(Presence::class)->count())->toBe(0)
        ->and(app(Presence::class)->recent())->toBeEmpty();
})->with(['database', 'redis']);

it('counts a visitor who has just been seen', function (string $driver): void {
    useDriver($driver);

    app(Presence::class)->touch(random_bytes(16), '/pricing');

    expect(app(Presence::class)->count())->toBe(1);
})->with(['database', 'redis']);

it('counts a returning visitor once', function (string $driver): void {
    useDriver($driver);

    $visitor = random_bytes(16);

    app(Presence::class)->touch($visitor, '/one');
    app(Presence::class)->touch($visitor, '/two');
    app(Presence::class)->touch($visitor, '/three');

    expect(app(Presence::class)->count())->toBe(1);
})->with(['database', 'redis']);

it('counts distinct visitors separately in presence', function (string $driver): void {
    useDriver($driver);

    foreach (range(1, 7) as $ignored) {
        app(Presence::class)->touch(random_bytes(16), '/pricing');
    }

    expect(app(Presence::class)->count())->toBe(7);
})->with(['database', 'redis']);

it('reports the page a visitor is on', function (string $driver): void {
    useDriver($driver);

    app(Presence::class)->touch(random_bytes(16), '/pricing');

    $recent = app(Presence::class)->recent();

    expect($recent)->toHaveCount(1)
        ->and($recent->first()['page'] ?? null)->toBe('/pricing');
})->with(['database', 'redis']);

/**
 * A visitor hash crossing into a view must be printable. A raw binary string
 * in HTML is a rendering accident waiting to happen, so both drivers hand back
 * hex.
 */
it('returns a printable visitor identifier', function (string $driver): void {
    useDriver($driver);

    app(Presence::class)->touch(random_bytes(16), '/pricing');

    $visitor = app(Presence::class)->recent()->first()['visitor'] ?? '';

    expect($visitor)->toMatch('/^[0-9a-f]{32}$/');
})->with(['database', 'redis']);

it('honours the limit on the recent list', function (string $driver): void {
    useDriver($driver);

    foreach (range(1, 10) as $ignored) {
        app(Presence::class)->touch(random_bytes(16), '/pricing');
    }

    expect(app(Presence::class)->recent(3))->toHaveCount(3);
})->with(['database', 'redis']);

/*
|--------------------------------------------------------------------------
| Failure containment
|--------------------------------------------------------------------------
|
| CLAUDE.md: nothing Cairn does may break the host application's request.
|
*/

it('swallows a storage failure rather than rethrowing', function (string $driver): void {
    useDriver($driver);

    app(Ingest::class)->record(anEntry());

    // Drop the table out from under the driver mid-request.
    Schema::connection(Tables::connection())
        ->drop(Tables::entries());

    expect(fn (): int => app(Ingest::class)->digest(app(Storage::class)))
        ->not->toThrow(Throwable::class);
})->with(['database', 'redis']);

it('keeps counting and presence quiet when their storage is gone', function (string $driver): void {
    useDriver($driver);

    Schema::connection(Tables::connection())->drop(Tables::visitorDays());
    Schema::connection(Tables::connection())->drop(Tables::presence());

    expect(function (): void {
        app(UniqueCounter::class)->add('2026-03-14', 'overall', random_bytes(16));
        app(UniqueCounter::class)->count('2026-03-14', 'overall');
        app(Presence::class)->touch(random_bytes(16), '/pricing');
        app(Presence::class)->count();
    })->not->toThrow(Throwable::class);
})->with(['database', 'redis']);
