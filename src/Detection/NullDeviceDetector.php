<?php

declare(strict_types=1);

namespace Divoto\Cairn\Detection;

use Divoto\Cairn\Contracts\DeviceDetector;
use Divoto\Cairn\Data\ClientHints;
use Divoto\Cairn\Data\Device;

/**
 * A device detector that identifies nothing.
 *
 * A placeholder for Phase 5, which ships the real matcher.
 */
final class NullDeviceDetector implements DeviceDetector
{
    public function detect(?string $userAgent, ClientHints $hints): Device
    {
        return Device::unknown();
    }
}
