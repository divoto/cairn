<?php

declare(strict_types=1);

use Divoto\Cairn\Enums\Browser;
use Divoto\Cairn\Enums\Channel;
use Divoto\Cairn\Enums\DeviceType;
use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\EntryType;
use Divoto\Cairn\Enums\GeoPrecision;
use Divoto\Cairn\Enums\OperatingSystem;
use Divoto\Cairn\Enums\Period;
use Divoto\Cairn\Enums\ScreenClass;

/*
|--------------------------------------------------------------------------
| Period
|--------------------------------------------------------------------------
*/

it('rolls hours into days into months', function (): void {
    expect(Period::Hour->parent())->toBe(Period::Day)
        ->and(Period::Day->parent())->toBe(Period::Month)
        ->and(Period::Month->parent())->toBeNull();
});

/**
 * Days vary across daylight-saving transitions and months vary by definition.
 * Only an hour may be computed by multiplication; the rest must go through a
 * calendar-aware library or the arithmetic silently drifts twice a year.
 */
it('treats only the hour as a fixed-length bucket', function (): void {
    expect(Period::Hour->isFixedLength())->toBeTrue()
        ->and(Period::Day->isFixedLength())->toBeFalse()
        ->and(Period::Month->isFixedLength())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| EntryType
|--------------------------------------------------------------------------
*/

it('requires a name for everything but a pageview', function (): void {
    expect(EntryType::Pageview->requiresName())->toBeFalse()
        ->and(EntryType::Event->requiresName())->toBeTrue()
        ->and(EntryType::Conversion->requiresName())->toBeTrue();
});

it('permits a monetary value only on a conversion', function (): void {
    expect(EntryType::Conversion->supportsValue())->toBeTrue()
        ->and(EntryType::Pageview->supportsValue())->toBeFalse()
        ->and(EntryType::Event->supportsValue())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| GeoPrecision
|--------------------------------------------------------------------------
*/

it('permits only levels at or below the configured precision', function (): void {
    expect(GeoPrecision::City->allows(GeoPrecision::Country))->toBeTrue()
        ->and(GeoPrecision::Country->allows(GeoPrecision::City))->toBeFalse()
        ->and(GeoPrecision::Country->allows(GeoPrecision::Country))->toBeTrue()
        ->and(GeoPrecision::None->allows(GeoPrecision::Country))->toBeFalse();
});

it('flags region and city as elevated re-identification risk', function (): void {
    expect(GeoPrecision::Region->isElevated())->toBeTrue()
        ->and(GeoPrecision::City->isElevated())->toBeTrue()
        ->and(GeoPrecision::Country->isElevated())->toBeFalse()
        ->and(GeoPrecision::None->isElevated())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Dimension
|--------------------------------------------------------------------------
*/

it('materialises a deliberately small set of dimensions', function (): void {
    $materialised = array_values(array_filter(
        Dimension::cases(),
        static fn (Dimension $d): bool => $d->isMaterialised(),
    ));

    expect($materialised)->toHaveCount(17)
        ->toContain(Dimension::Route)
        ->toContain(Dimension::Country)
        ->toContain(Dimension::EventName);
});

/**
 * Url, Region and City are recordable but not rolled up. A report asking for
 * them must throw rather than fall back to scanning raw entries.
 *
 * Language and ScreenClass used to sit here too. Both are bounded — a short
 * tag and four buckets — so rolling them up adds a predictable handful of rows
 * per bucket rather than one per distinct value, which is what kept Url out.
 */
it('does not materialise high-cardinality or sensitive dimensions', function (): void {
    expect(Dimension::Url->isMaterialised())->toBeFalse()
        ->and(Dimension::Region->isMaterialised())->toBeFalse()
        ->and(Dimension::City->isMaterialised())->toBeFalse();
});

it('materialises the two bounded dimensions the beacon and the headers give', function (): void {
    expect(Dimension::Language->isMaterialised())->toBeTrue()
        ->and(Dimension::ScreenClass->isMaterialised())->toBeTrue();
});

it('maps every dimension to a column', function (Dimension $dimension): void {
    expect($dimension->column())->not->toBe('')
        ->and($dimension->label())->not->toBe('');
})->with(array_map(static fn (Dimension $d): array => [$d], Dimension::cases()));

it('ties the geographic dimensions to the precision they require', function (): void {
    expect(Dimension::Country->requiredGeoPrecision())->toBe(GeoPrecision::Country)
        ->and(Dimension::Region->requiredGeoPrecision())->toBe(GeoPrecision::Region)
        ->and(Dimension::City->requiredGeoPrecision())->toBe(GeoPrecision::City)
        ->and(Dimension::Route->requiredGeoPrecision())->toBeNull();
});

/*
|--------------------------------------------------------------------------
| ScreenClass
|--------------------------------------------------------------------------
*/

it('buckets viewport widths and discards the exact value', function (int $width, ScreenClass $expected): void {
    expect(ScreenClass::fromWidth($width))->toBe($expected);
})->with([
    [0, ScreenClass::Unknown],
    [-100, ScreenClass::Unknown],
    [320, ScreenClass::Small],
    [639, ScreenClass::Small],
    [640, ScreenClass::Medium],
    [1023, ScreenClass::Medium],
    [1024, ScreenClass::Large],
    [1439, ScreenClass::Large],
    [1440, ScreenClass::ExtraLarge],
    [3840, ScreenClass::ExtraLarge],
]);

/*
|--------------------------------------------------------------------------
| Lookup enums
|--------------------------------------------------------------------------
|
| These are integer-backed and land in smallint columns. Their case values are
| permanent: renumbering one rewrites the meaning of every historical row, so
| these tests pin the numbers deliberately.
|
*/

it('pins the unknown case of every lookup enum to zero', function (): void {
    expect(Browser::Unknown->value)->toBe(0)
        ->and(OperatingSystem::Unknown->value)->toBe(0)
        ->and(DeviceType::Unknown->value)->toBe(0)
        ->and(ScreenClass::Unknown->value)->toBe(0);
});

it('pins the catch-all case of the open-ended lookups to 99', function (): void {
    expect(Browser::Other->value)->toBe(99)
        ->and(OperatingSystem::Other->value)->toBe(99);
});

it('gives every lookup case a distinct label', function (): void {
    $labels = array_map(static fn (Browser $b): string => $b->label(), Browser::cases());
    expect($labels)->toHaveCount(count(array_unique($labels)));

    $labels = array_map(static fn (OperatingSystem $o): string => $o->label(), OperatingSystem::cases());
    expect($labels)->toHaveCount(count(array_unique($labels)));

    $labels = array_map(static fn (Channel $c): string => $c->label(), Channel::cases());
    expect($labels)->toHaveCount(count(array_unique($labels)));
});

it('treats paid and email as campaign channels', function (): void {
    expect(Channel::Paid->isCampaign())->toBeTrue()
        ->and(Channel::Email->isCampaign())->toBeTrue()
        ->and(Channel::Organic->isCampaign())->toBeFalse()
        ->and(Channel::Direct->isCampaign())->toBeFalse();
});
