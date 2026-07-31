<?php

declare(strict_types=1);

namespace Divoto\Cairn\Detection;

use Divoto\Cairn\Contracts\DeviceDetector;
use Divoto\Cairn\Data\ClientHints;
use Divoto\Cairn\Data\Device;
use Divoto\Cairn\Enums\Browser;
use Divoto\Cairn\Enums\DeviceType;
use Divoto\Cairn\Enums\OperatingSystem;

/**
 * Classifies a request into a device type, browser and operating system.
 *
 * Coarse by design. Cairn reports on classes of device, not on models or
 * versions: a model name narrows the anonymity set of a visitor hash without
 * answering any question a site owner actually has.
 *
 * Order matters throughout. Every Chromium browser claims to be Chrome, every
 * browser claims to be Mozilla, and iPadOS claims to be macOS — so the
 * specific cases are tested before the general ones.
 *
 * Installing `matomo/device-detector` and binding it in place of this class
 * buys accuracy on long-tail agents. It is a suggestion, never a requirement.
 */
final class UserAgentDeviceDetector implements DeviceDetector
{
    public function detect(?string $userAgent, ClientHints $hints): Device
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return Device::unknown();
        }

        $ua = strtolower($userAgent);

        return new Device(
            type: $this->type($ua, $hints),
            browser: $this->browser($ua),
            os: $this->operatingSystem($ua, $hints),
        );
    }

    private function type(string $ua, ClientHints $hints): DeviceType
    {
        // A tablet also says "mobile" on Android, so tablets are tested first.
        if (str_contains($ua, 'ipad') || (str_contains($ua, 'android') && ! str_contains($ua, 'mobile'))) {
            return DeviceType::Tablet;
        }

        if (str_contains($ua, 'tablet') || str_contains($ua, 'kindle') || str_contains($ua, 'silk')) {
            return DeviceType::Tablet;
        }

        if (str_contains($ua, 'smart-tv') || str_contains($ua, 'smarttv') || str_contains($ua, 'appletv')
            || str_contains($ua, 'googletv') || str_contains($ua, 'hbbtv') || str_contains($ua, 'roku')) {
            return DeviceType::Television;
        }

        if (str_contains($ua, 'playstation') || str_contains($ua, 'xbox') || str_contains($ua, 'nintendo')) {
            return DeviceType::Console;
        }

        if (str_contains($ua, 'watch') && ! str_contains($ua, 'watchdog')) {
            return DeviceType::Wearable;
        }

        if (str_contains($ua, 'mobile') || str_contains($ua, 'iphone') || str_contains($ua, 'ipod')
            || str_contains($ua, 'android') || str_contains($ua, 'phone')) {
            return DeviceType::Mobile;
        }

        // A low-entropy client hint is more trustworthy than the agent string,
        // which browsers are actively freezing.
        if ($hints->mobile === true) {
            return DeviceType::Mobile;
        }

        if (str_contains($ua, 'windows') || str_contains($ua, 'macintosh') || str_contains($ua, 'linux')
            || str_contains($ua, 'x11') || str_contains($ua, 'cros')) {
            return DeviceType::Desktop;
        }

        return DeviceType::Unknown;
    }

    private function browser(string $ua): Browser
    {
        // Every Chromium browser claims Chrome, and several claim Safari, so
        // the derivatives must be tested before their base.
        return match (true) {
            str_contains($ua, 'brave') => Browser::Brave,
            str_contains($ua, 'vivaldi') => Browser::Vivaldi,
            str_contains($ua, 'duckduckgo') => Browser::DuckDuckGo,
            str_contains($ua, 'yabrowser') || str_contains($ua, 'yandex') => Browser::Yandex,
            str_contains($ua, 'samsungbrowser') => Browser::SamsungInternet,
            str_contains($ua, 'ucbrowser') => Browser::UcBrowser,
            str_contains($ua, 'edg/') || str_contains($ua, 'edge') || str_contains($ua, 'edgios')
                || str_contains($ua, 'edga') => Browser::Edge,
            str_contains($ua, 'opr/') || str_contains($ua, 'opera') => Browser::Opera,
            str_contains($ua, 'firefox') || str_contains($ua, 'fxios') => Browser::Firefox,
            str_contains($ua, 'chrome') || str_contains($ua, 'crios') || str_contains($ua, 'chromium') => Browser::Chrome,
            str_contains($ua, 'safari') => Browser::Safari,
            str_contains($ua, 'msie') || str_contains($ua, 'trident') => Browser::InternetExplorer,
            default => Browser::Unknown,
        };
    }

    private function operatingSystem(string $ua, ClientHints $hints): OperatingSystem
    {
        $platform = strtolower(trim($hints->platform ?? ''));

        if ($platform !== '') {
            $fromHint = match ($platform) {
                'windows' => OperatingSystem::Windows,
                'macos' => OperatingSystem::MacOS,
                'android' => OperatingSystem::Android,
                'ios' => OperatingSystem::IOS,
                'linux' => OperatingSystem::Linux,
                'chrome os', 'chromeos' => OperatingSystem::ChromeOS,
                default => null,
            };

            if ($fromHint !== null) {
                return $fromHint;
            }
        }

        // iPadOS reports itself as Macintosh, so it is tested first; Android
        // contains "linux", so it precedes Linux.
        return match (true) {
            str_contains($ua, 'ipad') => OperatingSystem::IPadOS,
            str_contains($ua, 'iphone') || str_contains($ua, 'ipod') => OperatingSystem::IOS,
            str_contains($ua, 'android') => OperatingSystem::Android,
            str_contains($ua, 'harmonyos') => OperatingSystem::HarmonyOS,
            str_contains($ua, 'cros') => OperatingSystem::ChromeOS,
            str_contains($ua, 'windows') => OperatingSystem::Windows,
            str_contains($ua, 'macintosh') || str_contains($ua, 'mac os x') => OperatingSystem::MacOS,
            str_contains($ua, 'freebsd') => OperatingSystem::FreeBSD,
            str_contains($ua, 'linux') || str_contains($ua, 'x11') => OperatingSystem::Linux,
            default => OperatingSystem::Unknown,
        };
    }
}
