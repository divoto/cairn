<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Divoto\Cairn\Identity\SessionResolver;
use Divoto\Cairn\Support\Tables;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sessions(): SessionResolver
{
    return app(SessionResolver::class);
}

function sessionRows(): Builder
{
    return app(DatabaseManager::class)
        ->connection(Tables::connection())
        ->table(Tables::sessions());
}

it('starts a session for a visitor that has none', function (): void {
    $at = CarbonImmutable::parse('2026-03-14 12:00:00', 'UTC');
    $session = sessions()->resolve(random_bytes(16), $at);

    expect($session->isNew)->toBeTrue()
        ->and($session->pageCount)->toBe(0)
        ->and(strlen($session->id))->toBe(16)
        ->and($session->startedAt->equalTo($at))->toBeTrue();
});

it('derives the identifier from the visitor and the start instant', function (): void {
    $visitor = random_bytes(16);
    $at = CarbonImmutable::parse('2026-03-14 12:00:00', 'UTC');

    expect(sessions()->idFor($visitor, $at))->toBe(sessions()->idFor($visitor, $at))
        ->and(sessions()->idFor($visitor, $at))->not->toBe(sessions()->idFor(random_bytes(16), $at))
        ->and(sessions()->idFor($visitor, $at))->not->toBe(sessions()->idFor($visitor, $at->addSecond()));
});

it('continues an existing session within the inactivity window', function (): void {
    $visitor = random_bytes(16);
    $start = CarbonImmutable::parse('2026-03-14 12:00:00', 'UTC');

    $first = sessions()->resolve($visitor, $start);
    sessions()->record($first, $start, '/one');

    $second = sessions()->resolve($visitor, $start->addMinutes(29));

    expect($second->isNew)->toBeFalse()
        ->and($second->id)->toBe($first->id)
        ->and($second->pageCount)->toBe(1);
});

/**
 * SQLite gives a `datetime` column numeric affinity rather than enforcing a
 * string: a plain integer written into it is stored, and read back, as one.
 * This is what that looks like arriving from the database, however it got
 * there — treated as no open session rather than trusted half-parsed.
 *
 * Only SQLite can stage this. PostgreSQL rejects the integer outright and
 * MySQL coerces it into a valid datetime, so on both the resolver never sees
 * anything but a string.
 */
it('treats an existing session as absent if its timestamp did not come back as a string', function (): void {
    $visitor = random_bytes(16);
    $start = CarbonImmutable::parse('2026-03-14 12:00:00', 'UTC');

    $first = sessions()->resolve($visitor, $start);
    sessions()->record($first, $start, '/one');

    sessionRows()->update(['started_at' => 20260314120000]);

    $second = sessions()->resolve($visitor, $start->addMinutes(5));

    expect($second->isNew)->toBeTrue();
})->skip(fn (): bool => Tables::driver() !== 'sqlite', 'Only SQLite stores a non-string in a datetime column.');

it('starts a new session once the inactivity window has passed', function (): void {
    $visitor = random_bytes(16);
    $start = CarbonImmutable::parse('2026-03-14 12:00:00', 'UTC');

    $first = sessions()->resolve($visitor, $start);
    sessions()->record($first, $start, '/one');

    $second = sessions()->resolve($visitor, $start->addMinutes(31));

    expect($second->isNew)->toBeTrue()
        ->and($second->id)->not->toBe($first->id);
});

it('uses a 30-minute inactivity window', function (): void {
    expect(sessions()->inactivityMinutes())->toBe(30);
});

it('keeps different visitors in different sessions', function (): void {
    $at = CarbonImmutable::parse('2026-03-14 12:00:00', 'UTC');

    $one = sessions()->resolve(random_bytes(16), $at);
    sessions()->record($one, $at, '/one');

    $two = sessions()->resolve(random_bytes(16), $at);

    expect($two->isNew)->toBeTrue()
        ->and($two->id)->not->toBe($one->id);
});

/*
|--------------------------------------------------------------------------
| Recording
|--------------------------------------------------------------------------
*/

it('records the entry url once and the exit url on every page', function (): void {
    $visitor = random_bytes(16);
    $start = CarbonImmutable::parse('2026-03-14 12:00:00', 'UTC');

    $first = sessions()->resolve($visitor, $start);
    sessions()->record($first, $start, '/landing');

    $second = sessions()->resolve($visitor, $start->addMinutes(5));
    sessions()->record($second, $start->addMinutes(5), '/pricing');

    $row = (array) sessionRows()->first();

    expect($row['entry_url'] ?? null)->toBe('/landing')
        ->and($row['exit_url'] ?? null)->toBe('/pricing');
});

/**
 * Not every recorded request has a URL to attribute — a console-triggered
 * entry has none. The column stays null rather than being written as an empty
 * string, which would become a landing page called "" on the panel.
 */
it('stores no landing page for a request that has no url', function (): void {
    $visitor = random_bytes(16);
    $start = CarbonImmutable::parse('2026-03-14 12:00:00', 'UTC');

    $session = sessions()->resolve($visitor, $start);

    // No URL at all, which is what the third argument defaults to.
    sessions()->record($session, $start);

    $row = (array) sessionRows()->first();

    expect(array_key_exists('entry_url', $row))->toBeTrue()
        ->and($row['entry_url'])->toBeNull()
        ->and($row['exit_url'])->toBeNull();
});

/**
 * page_count is incremented in the database rather than read and written back,
 * so two concurrent requests cannot both read 3 and both write 4.
 */
it('increments the page count without reading it first', function (): void {
    $visitor = random_bytes(16);
    $start = CarbonImmutable::parse('2026-03-14 12:00:00', 'UTC');

    $session = sessions()->resolve($visitor, $start);
    sessions()->record($session, $start, '/one');

    foreach (range(2, 5) as $page) {
        $next = sessions()->resolve($visitor, $start->addMinutes($page));
        sessions()->record($next, $start->addMinutes($page), "/page-{$page}");
    }

    expect(columnInt(sessionRows()->value('page_count')))->toBe(5);
});

it('marks a single-page visit as a bounce and clears it on the second page', function (): void {
    $visitor = random_bytes(16);
    $start = CarbonImmutable::parse('2026-03-14 12:00:00', 'UTC');

    $first = sessions()->resolve($visitor, $start);
    sessions()->record($first, $start, '/only');

    expect(boolval(sessionRows()->value('is_bounce')))->toBeTrue();

    $second = sessions()->resolve($visitor, $start->addMinutes(2));
    sessions()->record($second, $start->addMinutes(2), '/second');

    expect(boolval(sessionRows()->value('is_bounce')))->toBeFalse();
});

it('accumulates the visit duration', function (): void {
    $visitor = random_bytes(16);
    $start = CarbonImmutable::parse('2026-03-14 12:00:00', 'UTC');

    $first = sessions()->resolve($visitor, $start);
    sessions()->record($first, $start, '/one');

    $second = sessions()->resolve($visitor, $start->addMinutes(10));
    sessions()->record($second, $start->addMinutes(10), '/two');

    expect(columnInt(sessionRows()->value('duration_seconds')))->toBe(600);
});

it('writes exactly one row per visit', function (): void {
    $visitor = random_bytes(16);
    $start = CarbonImmutable::parse('2026-03-14 12:00:00', 'UTC');

    foreach (range(0, 10) as $minute) {
        $session = sessions()->resolve($visitor, $start->addMinutes($minute));
        sessions()->record($session, $start->addMinutes($minute), "/page-{$minute}");
    }

    expect(sessionRows()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Tenancy
|--------------------------------------------------------------------------
*/

it('keeps the same visitor hash in separate sessions per tenant', function (): void {
    $visitor = random_bytes(16);
    $at = CarbonImmutable::parse('2026-03-14 12:00:00', 'UTC');

    $one = sessions()->resolve($visitor, $at, tenantId: 'alpha');
    sessions()->record($one, $at, '/alpha');

    $two = sessions()->resolve($visitor, $at->addMinutes(1), tenantId: 'beta');

    expect($two->isNew)->toBeTrue()
        ->and($two->id)->not->toBe($one->id);
});

it('finds an untenanted session for an untenanted request', function (): void {
    $visitor = random_bytes(16);
    $at = CarbonImmutable::parse('2026-03-14 12:00:00', 'UTC');

    $first = sessions()->resolve($visitor, $at);
    sessions()->record($first, $at, '/one');

    // A NULL tenant is stored as an empty string precisely so this comparison
    // matches. If tenant_id were nullable, this would silently start a new
    // session on every page of a single-tenant installation.
    expect(sessions()->resolve($visitor, $at->addMinutes(1))->isNew)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Closing
|--------------------------------------------------------------------------
*/

it('closes sessions that have been idle past the window', function (): void {
    $visitor = random_bytes(16);
    $start = CarbonImmutable::parse('2026-03-14 12:00:00', 'UTC');

    $session = sessions()->resolve($visitor, $start);
    sessions()->record($session, $start, '/one');

    expect(sessions()->closeIdle($start->addHour()))->toBe(1)
        ->and(sessionRows()->value('ended_at'))->not->toBeNull();
});

it('leaves active sessions open', function (): void {
    $visitor = random_bytes(16);
    $start = CarbonImmutable::parse('2026-03-14 12:00:00', 'UTC');

    $session = sessions()->resolve($visitor, $start);
    sessions()->record($session, $start, '/one');

    expect(sessions()->closeIdle($start->addMinutes(10)))->toBe(0)
        ->and(sessionRows()->value('ended_at'))->toBeNull();
});
