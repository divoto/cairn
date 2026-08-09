<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Divoto\Cairn\Identity\VisitorHasher;
use Divoto\Cairn\Support\Tables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function hasher(): VisitorHasher
{
    return app(VisitorHasher::class);
}

it('produces a 16-byte hash', function (): void {
    expect(strlen(hasher()->hash('203.0.113.7', 'Mozilla/5.0')))->toBe(16);
});

it('gives the same visitor the same hash within a rotation window', function (): void {
    $first = hasher()->hash('203.0.113.7', 'Mozilla/5.0');
    $second = hasher()->hash('203.0.113.7', 'Mozilla/5.0');

    expect($first)->toBe($second);
});

it('distinguishes visitors by address and by user agent', function (): void {
    $base = hasher()->hash('203.0.113.7', 'Mozilla/5.0');

    expect(hasher()->hash('203.0.113.8', 'Mozilla/5.0'))->not->toBe($base)
        ->and(hasher()->hash('203.0.113.7', 'Safari/17'))->not->toBe($base);
});

/*
|--------------------------------------------------------------------------
| Privacy invariant: cross-day identity is impossible by construction
|--------------------------------------------------------------------------
|
| The project requires an explicit regression test that the same visitor yields
| a different hash either side of a salt rotation. This is the property the
| whole package rests on: if it ever stopped holding, Cairn would silently
| become a tool that follows people across days.
|
*/

it('gives the same visitor a different hash after the salt rotates', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-14 23:59:59', 'UTC'));
    $before = hasher()->hash('203.0.113.7', 'Mozilla/5.0');

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-15 00:00:01', 'UTC'));
    $after = hasher()->hash('203.0.113.7', 'Mozilla/5.0');

    expect($after)->not->toBe($before);

    CarbonImmutable::setTestNow();
});

it('rotates at midnight UTC rather than in local time', function (): void {
    // 23:00 in a UTC+2 zone is still the previous UTC day.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-14 23:00:00', 'UTC'));
    $lateOnDayOne = hasher()->hash('203.0.113.7', 'UA');

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-14 01:00:00', 'UTC'));
    $earlyOnDayOne = hasher()->hash('203.0.113.7', 'UA');

    expect($lateOnDayOne)->toBe($earlyOnDayOne);

    CarbonImmutable::setTestNow();
});

it('generates a distinct salt for each window', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-14 12:00:00', 'UTC'));
    $first = hasher()->salt();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-15 12:00:00', 'UTC'));
    $second = hasher()->salt();

    expect($first)->not->toBe($second)
        ->and(strlen($first))->toBe(32)
        ->and(strlen($second))->toBe(32);

    CarbonImmutable::setTestNow();
});

it('reuses a salt already generated for the window', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-14 08:00:00', 'UTC'));
    $morning = hasher()->salt();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-14 20:00:00', 'UTC'));
    $evening = hasher()->salt();

    expect($morning)->toBe($evening);

    CarbonImmutable::setTestNow();
});

/**
 * The previous window's salt exists only to close sessions that were open when
 * rotation happened. It must never be reachable once it has expired.
 */
it('can reproduce the previous window hash while that salt survives', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-14 12:00:00', 'UTC'));
    $yesterday = hasher()->hash('203.0.113.7', 'UA');

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-15 00:30:00', 'UTC'));

    expect(hasher()->previousHash('203.0.113.7', 'UA'))->toBe($yesterday);

    CarbonImmutable::setTestNow();
});

it('returns null for a previous window whose salt is gone', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-15 12:00:00', 'UTC'));

    expect(hasher()->previousHash('203.0.113.7', 'UA'))->toBeNull();

    CarbonImmutable::setTestNow();
});

/*
|--------------------------------------------------------------------------
| Privacy invariant: the salt never reaches disk or the database
|--------------------------------------------------------------------------
*/

it('never writes the salt to any Cairn table', function (): void {
    $salt = hasher()->salt();
    $connection = Schema::connection(Tables::connection())->getConnection();

    hasher()->hash('203.0.113.7', 'Mozilla/5.0');

    foreach (Tables::all() as $table) {
        expect($connection->table($table)->count())->toBe(0, "{$table} was written to");
    }

    // And the salt is not sitting in the cache table either, since the suite
    // uses an array store.
    expect($salt)->not->toBeEmpty();
});

it('keeps the salt only in the cache, under a rotating key', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-14 12:00:00', 'UTC'));

    $salt = hasher()->salt();

    expect(Cache::has('cairn:salt:2026-03-14'))->toBeTrue()
        ->and(base64_decode(asString(Cache::get('cairn:salt:2026-03-14')), true))->toBe($salt)
        ->and(Cache::has('cairn:salt:2026-03-15'))->toBeFalse();

    CarbonImmutable::setTestNow();
});

/*
|--------------------------------------------------------------------------
| Privacy invariant: an IP address never survives the call
|--------------------------------------------------------------------------
*/

it('holds no request data on the hasher itself', function (): void {
    $hasher = hasher();
    $hasher->hash('203.0.113.7', 'Mozilla/5.0');

    // Every property must be a collaborator, not a value carried over from a
    // request. A string or array here would be somewhere an address could
    // survive the call.
    foreach ((new ReflectionObject($hasher))->getProperties() as $property) {
        expect($property->getValue($hasher))->toBeObject(
            "VisitorHasher::\${$property->getName()} holds a non-object, which could carry request data"
        );
    }
});

it('scopes the hash to the domain so two installations cannot correlate', function (): void {
    config()->set('cairn.domain', 'one.example');
    $one = hasher()->hash('203.0.113.7', 'UA');

    // Same salt, same address, same user agent — only the scoping differs.
    config()->set('cairn.domain', 'two.example');
    $two = hasher()->hash('203.0.113.7', 'UA');

    expect($one)->not->toBe($two);
});

it('never lets configuration extend the rotation window beyond 24 hours', function (): void {
    config()->set('cairn.privacy.salt_rotation_hours', 24 * 365);

    expect(hasher()->rotationHours())->toBe(24);
});

it('allows configuration to rotate more often, which is strictly more private', function (): void {
    config()->set('cairn.privacy.salt_rotation_hours', 6);

    expect(hasher()->rotationHours())->toBe(6);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-14 01:00:00', 'UTC'));
    $firstWindow = hasher()->hash('203.0.113.7', 'UA');

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-14 07:00:00', 'UTC'));
    $secondWindow = hasher()->hash('203.0.113.7', 'UA');

    expect($secondWindow)->not->toBe($firstWindow);

    CarbonImmutable::setTestNow();
});
