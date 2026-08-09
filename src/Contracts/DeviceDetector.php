<?php

declare(strict_types=1);

namespace Divoto\Cairn\Contracts;

use Divoto\Cairn\Data\ClientHints;
use Divoto\Cairn\Data\Device;

/**
 * Classifies a request into a device type, browser and operating system.
 *
 * Implementations return coarse enum cases only. Resolving a precise model or
 * a full version string is not an improvement Cairn wants: it narrows the
 * anonymity set of a visitor hash without answering any question a site owner
 * actually has.
 *
 * The shipped default is a small built-in matcher. Installing
 * `matomo/device-detector` swaps in a more accurate implementation; it is a
 * `suggest`, never a `require`.
 */
interface DeviceDetector
{
    /**
     * Classify a user agent, refined by any low-entropy client hints present.
     */
    public function detect(?string $userAgent, ClientHints $hints): Device;
}
