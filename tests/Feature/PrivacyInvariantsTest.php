<?php

declare(strict_types=1);

use Divoto\Cairn\Contracts\GeoResolver;
use Divoto\Cairn\Data\ClientHints;
use Divoto\Cairn\Data\Device;
use Divoto\Cairn\Data\Entry;
use Divoto\Cairn\Data\GeoLocation;
use Divoto\Cairn\Enums\EntryType;
use Divoto\Cairn\Enums\GeoPrecision;

/*
|--------------------------------------------------------------------------
| Privacy invariants
|--------------------------------------------------------------------------
|
| The project requires these to be covered by explicit regression tests rather
| than incidentally. The full set arrives with the code it guards — the salt
| rotation and DNT tests need Phase 3, and the "no Set-Cookie" test needs
| Phase 5's middleware. What is testable at Phase 1 is the shape of the data
| structures themselves, which is where a leak would have to begin.
|
*/

/**
 * An IP address must never reach a persisted structure. Entry is the object
 * that becomes a database row, so if it cannot hold an address, no ingest
 * driver can accidentally write one.
 */
it('has no field on any value object that could hold an IP address', function (object $subject): void {
    $forbidden = ['ip', 'ipaddress', 'ipv4', 'ipv6', 'remoteaddr', 'clientip', 'address'];

    $reflection = new ReflectionObject($subject);

    foreach ($reflection->getProperties() as $property) {
        $normalised = strtolower(str_replace('_', '', $property->getName()));

        expect($forbidden)->not->toContain(
            $normalised,
            "{$reflection->getName()}::\${$property->getName()} looks like it holds an IP address"
        );
    }
})->with([
    // Constructed lazily: a dataset is built before the application boots, and
    // anEntry() needs now(). These must stay arrow functions rather than
    // first-class callables — Pest binds dataset closures to the test
    // instance, and a static callable cannot be bound.
    'entry' => [fn (): Entry => anEntry()],
    'device' => [fn (): Device => Device::unknown()],
    'location' => [fn (): GeoLocation => GeoLocation::unknown()],
    'hints' => [fn (): ClientHints => ClientHints::none()],
]);

/**
 * The user agent is a fingerprinting signal. It is used to derive the visitor
 * hash and then discarded — it must not survive on the entry.
 */
it('does not carry the raw user agent onto an entry', function (): void {
    $properties = array_map(
        static fn (ReflectionProperty $p): string => strtolower(str_replace('_', '', $p->getName())),
        (new ReflectionClass(Entry::class))->getProperties(),
    );

    expect($properties)->not->toContain('useragent')
        ->and($properties)->not->toContain('ua');
});

/**
 * GeoResolver is the single boundary an IP address is allowed to cross, and it
 * must not hand one back in any form.
 */
it('never returns an IP address from the geo resolver', function (): void {
    $return = (new ReflectionMethod(GeoResolver::class, 'resolve'))->getReturnType();

    expect((string) $return)->toBe('?'.GeoLocation::class);
});

it('discards geography finer than the configured precision', function (): void {
    $precise = new GeoLocation(country: 'GB', region: 'Scotland', city: 'Glasgow');

    $country = $precise->reduceTo(GeoPrecision::Country);

    expect($country->country)->toBe('GB')
        ->and($country->region)->toBeNull()
        ->and($country->city)->toBeNull();

    $region = $precise->reduceTo(GeoPrecision::Region);

    expect($region->country)->toBe('GB')
        ->and($region->region)->toBe('Scotland')
        ->and($region->city)->toBeNull();

    expect($precise->reduceTo(GeoPrecision::None)->isKnown())->toBeFalse();
});

it('cannot widen geography beyond what the resolver found', function (): void {
    $coarse = new GeoLocation(country: 'GB');

    $widened = $coarse->reduceTo(GeoPrecision::City);

    expect($widened->country)->toBe('GB')
        ->and($widened->region)->toBeNull()
        ->and($widened->city)->toBeNull();
});

/**
 * An entry is immutable. If it were not, a recorder could attach a user id to
 * an entry that the privacy configuration had already decided should not carry
 * one.
 */
it('cannot be mutated after construction', function (): void {
    expect((new ReflectionClass(Entry::class))->isReadOnly())->toBeTrue();
});

it('attaches a location without ever exposing what produced it', function (): void {
    $entry = new Entry(
        occurredAt: now()->toImmutable(),
        type: EntryType::Pageview,
        visitor: random_bytes(16),
    );

    $located = $entry->withLocation(new GeoLocation(country: 'GB'));

    expect($located->country)->toBe('GB')
        ->and($located)->not->toBe($entry)
        ->and($entry->country)->toBeNull();
});
