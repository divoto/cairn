<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Divoto\Cairn\Data\ClientHints;
use Divoto\Cairn\Data\Device;
use Divoto\Cairn\Data\GeoLocation;
use Divoto\Cairn\Data\ReportRow;
use Divoto\Cairn\Data\Session;
use Divoto\Cairn\Detection\UserAgentDeviceDetector;
use Divoto\Cairn\Enums\Browser;
use Divoto\Cairn\Enums\Channel;
use Divoto\Cairn\Enums\Comparison;
use Divoto\Cairn\Enums\DeviceType;
use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Enums\OperatingSystem;
use Divoto\Cairn\Enums\ScreenClass;

/*
|--------------------------------------------------------------------------
| Labels
|--------------------------------------------------------------------------
|
| Every lookup case reaches a dashboard, so every one needs a label. A missing
| arm would surface as a blank cell rather than an error.
|
*/

it('labels every case of every lookup enum', function (): void {
    foreach (DeviceType::cases() as $case) {
        expect($case->label())->not->toBe('');
    }

    foreach (ScreenClass::cases() as $case) {
        expect($case->label())->not->toBe('');
    }

    foreach (Browser::cases() as $case) {
        expect($case->label())->not->toBe('');
    }

    foreach (OperatingSystem::cases() as $case) {
        expect($case->label())->not->toBe('');
    }

    foreach (Channel::cases() as $case) {
        expect($case->label())->not->toBe('');
    }
});

/**
 * Channel, device, browser and OS are stored as integers. Rendering the stored
 * value would put "3" on the dashboard where "Social" belongs.
 */
it('renders a stored lookup value as its label', function (): void {
    expect(Dimension::Channel->display((string) Channel::Social->value))->toBe('Social')
        ->and(Dimension::DeviceType->display((string) DeviceType::Mobile->value))->toBe('Mobile')
        ->and(Dimension::Browser->display((string) Browser::Firefox->value))->toBe('Firefox')
        ->and(Dimension::OperatingSystem->display((string) OperatingSystem::Android->value))->toBe('Android')
        ->and(Dimension::ScreenClass->display((string) ScreenClass::Large->value))->toBe('Large (1024–1439px)');
});

it('passes a non-lookup dimension through unchanged', function (): void {
    expect(Dimension::Route->display('pricing.index'))->toBe('pricing.index');
});

it('renders a country code as its name', function (): void {
    expect(Dimension::Country->display('GB'))->toBe('United Kingdom')
        ->and(Dimension::Country->display('pk'))->toBe('Pakistan');
});

/**
 * A geo database is free to return a code this list has never heard of, and a
 * row that says "ZZ" is still more use to a reader than a row that says nothing.
 */
it('passes through a country code it does not know', function (): void {
    expect(Dimension::Country->display('ZZ'))->toBe('ZZ');
});

it('renders an absent dimension value as a dash', function (): void {
    expect(Dimension::Route->display(null))->toBe('—')
        ->and(Dimension::Route->display(''))->toBe('—');
});

it('passes through an integer with no matching case', function (): void {
    expect(Dimension::Browser->display('9999'))->toBe('9999');
});

it('flags the one dimension that needs the beacon', function (): void {
    expect(Dimension::ScreenClass->requiresBeacon())->toBeTrue()
        ->and(Dimension::Route->requiresBeacon())->toBeFalse();
});

it('has no comparison window when comparison is off', function (): void {
    expect(Comparison::None->windowFor(now()->toImmutable(), now()->toImmutable()))->toBeNull()
        ->and(Comparison::None->label())->toBe('No comparison')
        ->and(Comparison::PreviousPeriod->label())->toBe('Previous period')
        ->and(Comparison::PreviousYear->label())->toBe('Previous year');
});

/*
|--------------------------------------------------------------------------
| Value objects
|--------------------------------------------------------------------------
*/

it('knows whether a visit was a bounce and how long it lasted', function (): void {
    $start = CarbonImmutable::parse('2026-03-14 09:00:00', 'UTC');

    $single = new Session(
        id: random_bytes(16),
        visitor: random_bytes(16),
        startedAt: $start,
        lastActivityAt: $start,
        pageCount: 1,
    );

    $longer = new Session(
        id: random_bytes(16),
        visitor: random_bytes(16),
        startedAt: $start,
        lastActivityAt: $start->addMinutes(10),
        pageCount: 4,
    );

    expect($single->isBounce())->toBeTrue()
        ->and($single->durationSeconds())->toBe(0)
        ->and($longer->isBounce())->toBeFalse()
        ->and($longer->durationSeconds())->toBe(600);
});

it('reports whether any client hint was sent', function (): void {
    expect(ClientHints::none()->isEmpty())->toBeTrue()
        ->and((new ClientHints(platform: 'macOS'))->isEmpty())->toBeFalse()
        ->and((new ClientHints(mobile: false))->isEmpty())->toBeFalse();
});

it('reports whether a device was identified at all', function (): void {
    expect(Device::unknown()->isKnown())->toBeFalse()
        ->and((new Device(type: DeviceType::Mobile))->isKnown())->toBeTrue()
        ->and((new Device(browser: Browser::Chrome))->isKnown())->toBeTrue()
        ->and((new Device(os: OperatingSystem::Linux))->isKnown())->toBeTrue();
});

it('attaches a device to an entry without mutating the original', function (): void {
    $entry = anEntry();
    $device = new Device(DeviceType::Mobile, Browser::Safari, OperatingSystem::IOS);

    $updated = $entry->withDevice($device);

    expect($updated->deviceType)->toBe(DeviceType::Mobile)
        ->and($updated->browser)->toBe(Browser::Safari)
        ->and($entry->deviceType)->toBeNull();
});

it('reports whether a location was resolved', function (): void {
    expect(GeoLocation::unknown()->isKnown())->toBeFalse()
        ->and((new GeoLocation(country: 'GB'))->isKnown())->toBeTrue();
});

it('reads a dimension and a metric off a report row', function (): void {
    $row = new ReportRow(
        dimensions: ['route' => 'pricing.index'],
        metrics: [Metric::Pageviews->value => 12.0],
    );

    expect($row->dimension('route'))->toBe('pricing.index')
        ->and($row->dimension('missing'))->toBeNull()
        ->and($row->metric(Metric::Pageviews))->toBe(12.0)
        ->and($row->metric(Metric::Sessions))->toBeNull()
        ->and($row->change(Metric::Pageviews))->toBeNull();
});

it('drops empty sections when exporting a row', function (): void {
    $exported = (new ReportRow(metrics: [Metric::Pageviews->value => 1.0]))->toArray();

    expect($exported)->toHaveKey('metrics')
        ->and($exported)->not->toHaveKey('dimensions')
        ->and($exported)->not->toHaveKey('previous');
});

/*
|--------------------------------------------------------------------------
| Device detection
|--------------------------------------------------------------------------
|
| Order matters throughout: every Chromium browser claims to be Chrome, every
| browser claims to be Mozilla, and iPadOS claims to be macOS. The specific
| cases have to be tested before the general ones, which is exactly what these
| assertions pin.
|
*/

it('classifies a user agent', function (string $agent, DeviceType $type, Browser $browser, OperatingSystem $os): void {
    $device = (new UserAgentDeviceDetector)->detect($agent, ClientHints::none());

    expect($device->type)->toBe($type)
        ->and($device->browser)->toBe($browser)
        ->and($device->os)->toBe($os);
})->with([
    'chrome on macOS' => [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        DeviceType::Desktop, Browser::Chrome, OperatingSystem::MacOS,
    ],
    'safari on iPhone' => [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
        DeviceType::Mobile, Browser::Safari, OperatingSystem::IOS,
    ],
    'safari on iPad, which claims to be a Mac' => [
        'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/604.1',
        DeviceType::Tablet, Browser::Safari, OperatingSystem::IPadOS,
    ],
    'firefox on Windows' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:124.0) Gecko/20100101 Firefox/124.0',
        DeviceType::Desktop, Browser::Firefox, OperatingSystem::Windows,
    ],
    'edge, which also claims Chrome' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36 Edg/122.0.0.0',
        DeviceType::Desktop, Browser::Edge, OperatingSystem::Windows,
    ],
    'opera, which also claims Chrome' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36 OPR/108.0.0.0',
        DeviceType::Desktop, Browser::Opera, OperatingSystem::Windows,
    ],
    'chrome on Android' => [
        'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Mobile Safari/537.36',
        DeviceType::Mobile, Browser::Chrome, OperatingSystem::Android,
    ],
    'android tablet, which omits Mobile' => [
        'Mozilla/5.0 (Linux; Android 14; SM-X200) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        DeviceType::Tablet, Browser::Chrome, OperatingSystem::Android,
    ],
    'samsung internet' => [
        'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/23.0 Chrome/115.0.0.0 Mobile Safari/537.36',
        DeviceType::Mobile, Browser::SamsungInternet, OperatingSystem::Android,
    ],
    'firefox on Linux' => [
        'Mozilla/5.0 (X11; Linux x86_64; rv:124.0) Gecko/20100101 Firefox/124.0',
        DeviceType::Desktop, Browser::Firefox, OperatingSystem::Linux,
    ],
    'chromebook' => [
        'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        DeviceType::Desktop, Browser::Chrome, OperatingSystem::ChromeOS,
    ],
    'a games console' => [
        'Mozilla/5.0 (PlayStation; PlayStation 5/2.26) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.0 Safari/605.1.15',
        DeviceType::Console, Browser::Safari, OperatingSystem::Unknown,
    ],
    'a television' => [
        'Mozilla/5.0 (SMART-TV; Linux; Tizen 6.0) AppleWebKit/537.36 (KHTML, like Gecko) Version/6.0 Chrome/76.0 Safari/537.36',
        DeviceType::Television, Browser::Chrome, OperatingSystem::Linux,
    ],
    'internet explorer' => [
        'Mozilla/5.0 (Windows NT 10.0; Trident/7.0; rv:11.0) like Gecko',
        DeviceType::Desktop, Browser::InternetExplorer, OperatingSystem::Windows,
    ],
]);

it('identifies nothing without a user agent', function (?string $agent): void {
    expect((new UserAgentDeviceDetector)->detect($agent, ClientHints::none())->isKnown())->toBeFalse();
})->with([[null], [''], ['   ']]);

/**
 * A low-entropy client hint is more trustworthy than the agent string, which
 * browsers are actively freezing.
 */
it('prefers a client hint over the agent string', function (): void {
    $device = (new UserAgentDeviceDetector)->detect(
        'Mozilla/5.0 (Unknown)',
        new ClientHints(platform: 'Android', mobile: true),
    );

    expect($device->os)->toBe(OperatingSystem::Android)
        ->and($device->type)->toBe(DeviceType::Mobile);
});

it('reads every platform a client hint can report', function (string $platform, OperatingSystem $os): void {
    $device = (new UserAgentDeviceDetector)->detect('Mozilla/5.0 (Unknown)', new ClientHints(platform: $platform));

    expect($device->os)->toBe($os);
})->with([
    ['Windows', OperatingSystem::Windows],
    ['macOS', OperatingSystem::MacOS],
    ['Android', OperatingSystem::Android],
    ['iOS', OperatingSystem::IOS],
    ['Linux', OperatingSystem::Linux],
    ['Chrome OS', OperatingSystem::ChromeOS],
]);

it('ignores a platform hint it does not recognise', function (): void {
    $device = (new UserAgentDeviceDetector)->detect(
        'Mozilla/5.0 (Windows NT 10.0) Chrome/122.0',
        new ClientHints(platform: 'Haiku'),
    );

    expect($device->os)->toBe(OperatingSystem::Windows);
});
