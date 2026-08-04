<?php

declare(strict_types=1);

use Divoto\Cairn\Contracts\Presence;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Contracts\UniqueCounter;
use Divoto\Cairn\Data\Entry;
use Divoto\Cairn\Enums\EntryType;
use Divoto\Cairn\Enums\Period;
use Divoto\Cairn\Identity\SessionResolver;
use Divoto\Cairn\Maintenance\Eraser;
use Divoto\Cairn\Support\Binary;
use Divoto\Cairn\Support\Tables;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Binary columns
|--------------------------------------------------------------------------
|
| Every table Cairn writes on the request path holds a raw 16-byte hash, and
| every driver that writes one swallows its own exceptions so that analytics
| can never break a response. The two together are why this went unnoticed:
| on PostgreSQL a raw hash bound as a text parameter is rejected outright, and
| the rejection was reported to the log rather than raised. The tables stayed
| empty and everything else looked healthy.
|
| These tests therefore assert that the write *landed*, not that it did not
| throw. A driver that silently records nothing must fail here.
|
*/

function connection(): Connection
{
    return app(DatabaseManager::class)->connection(Tables::connection());
}

/**
 * Every `visitor` hash in a table, decoded.
 *
 * @return list<string>
 */
function visitorHashes(string $table): array
{
    return array_values(
        connection()->table(Tables::name($table))->get()
            ->map(static fn (object $row): string => binaryValue(((array) $row)['visitor'] ?? ''))
            ->all()
    );
}

it('binds a raw hash in a form the configured engine accepts', function (): void {
    $connection = connection();
    $visitor = "\x00\xff\x01\xfe".random_bytes(12);

    $bound = Binary::bind($connection, $visitor);

    if ($connection->getDriverName() === 'pgsql') {
        // Emitted as a literal rather than bound: PostgreSQL validates text
        // parameters against the database encoding and would reject the raw
        // bytes with SQLSTATE 22021.
        expect($bound)->toBeInstanceOf(ExpressionContract::class);

        $literal = $bound instanceof ExpressionContract
            ? $bound->getValue($connection->getQueryGrammar())
            : null;

        expect($literal)->toBe("'\\x".bin2hex($visitor)."'::bytea");

        return;
    }

    // Everywhere else the parameter binds correctly, and inlining it would
    // cost prepared-statement reuse for nothing.
    expect($bound)->toBe($visitor);
});

it('leaves null and empty values alone', function (): void {
    expect(Binary::bind(connection(), null))->toBeNull()
        ->and(Binary::bind(connection(), ''))->toBe('');
});

it('reads a binary column back identically on every engine', function (): void {
    $visitor = "\x00\xff\x01\xfe".random_bytes(12);

    app(Presence::class)->touch($visitor, '/pricing');

    expect(visitorHashes('presence'))->toBe([$visitor]);
});

/*
|--------------------------------------------------------------------------
| Each driver that writes a hash
|--------------------------------------------------------------------------
*/

it('stores an entry with its visitor and session hashes', function (): void {
    $visitor = random_bytes(16);
    $session = random_bytes(16);

    app(Storage::class)->store(new Collection([
        new Entry(
            occurredAt: now()->toImmutable(),
            type: EntryType::Pageview,
            visitor: $visitor,
            session: $session,
        ),
    ]));

    $row = (array) connection()->table(Tables::entries())->first();

    expect(connection()->table(Tables::entries())->count())->toBe(1)
        ->and(binaryValue($row['visitor'] ?? ''))->toBe($visitor)
        ->and(binaryValue($row['session'] ?? ''))->toBe($session);
});

it('stores a null session without turning it into an empty hash', function (): void {
    app(Storage::class)->store(new Collection([anEntry()]));

    expect(connection()->table(Tables::entries())->value('session'))->toBeNull();
});

it('opens, finds and updates a session', function (): void {
    $visitor = random_bytes(16);
    $resolver = app(SessionResolver::class);
    $at = now()->toImmutable();

    $first = $resolver->resolve($visitor, $at);
    $resolver->record($first, $at, '/pricing');

    expect($first->isNew)->toBeTrue()
        ->and(connection()->table(Tables::sessions())->count())->toBe(1)
        ->and(visitorHashes('sessions'))->toBe([$visitor]);

    // The second request must find the row the first one wrote rather than
    // opening a second visit — which is what a failed WHERE would look like.
    $second = $resolver->resolve($visitor, $at->addMinutes(2));
    $resolver->record($second, $at->addMinutes(2), '/checkout');

    expect($second->isNew)->toBeFalse()
        ->and($second->id)->toBe($first->id)
        ->and(connection()->table(Tables::sessions())->count())->toBe(1)
        ->and(columnInt(connection()->table(Tables::sessions())->value('page_count')))->toBe(2);
});

it('counts a unique visitor and finds it again', function (): void {
    $counter = app(UniqueCounter::class);
    $visitor = random_bytes(16);

    $counter->add('2026-03-14', 'overall', $visitor);
    $counter->add('2026-03-14', 'overall', $visitor);

    expect($counter->count('2026-03-14', 'overall'))->toBe(1)
        ->and(visitorHashes('visitor_days'))->toBe([$visitor]);
})->skip(fn (): bool => config('cairn.driver') === 'redis', 'Redis counts with HyperLogLog.');

it('materialises an aggregate with its key hash', function (): void {
    app(Storage::class)->store(new Collection([anEntry()]));

    $written = app(Storage::class)->rollup(
        now()->toImmutable()->startOfDay(),
        now()->toImmutable()->endOfDay(),
        Period::Day,
    );

    expect($written)->toBeGreaterThan(0)
        ->and(connection()->table(Tables::aggregates())->count())->toBeGreaterThan(0);
});

/*
|--------------------------------------------------------------------------
| Reading a hash back out
|--------------------------------------------------------------------------
*/

it('erases a visitor addressed by their hex hash', function (): void {
    $visitor = random_bytes(16);

    app(Storage::class)->store(new Collection([anEntry(visitor: $visitor)]));
    app(Presence::class)->touch($visitor, '/pricing');

    $removed = app(Eraser::class)->forgetVisitor(bin2hex($visitor));

    expect($removed['entries'])->toBe(1)
        ->and($removed['presence'])->toBe(1)
        ->and(connection()->table(Tables::entries())->count())->toBe(0);
});

it('exports a visitor as JSON-encodable hex', function (): void {
    $visitor = random_bytes(16);

    app(Storage::class)->store(new Collection([anEntry(visitor: $visitor)]));

    $export = app(Eraser::class)->exportVisitor(bin2hex($visitor));

    // json_encode cannot represent a stream, which is what PostgreSQL hands
    // back for a bytea column — so this fails outright if the export path
    // reads one of these columns without decoding it.
    $encoded = json_encode($export, JSON_THROW_ON_ERROR);

    $entries = $export['entries'];
    $first = is_array($entries) ? ($entries[0] ?? null) : null;

    expect($encoded)->toBeString()
        ->and($entries)->toHaveCount(1)
        ->and(is_array($first) ? $first['visitor'] ?? null : null)->toBe(bin2hex($visitor));
});
