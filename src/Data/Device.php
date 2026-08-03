<?php

declare(strict_types=1);

namespace Divoto\Cairn\Data;

use Divoto\Cairn\Enums\Browser;
use Divoto\Cairn\Enums\DeviceType;
use Divoto\Cairn\Enums\OperatingSystem;

/**
 * What a user agent string and client hints resolved to.
 *
 * Everything here is a coarse enum case. The originating user agent string is
 * not carried on this object: it is a fingerprinting signal, it is used to
 * derive the visitor hash and then discarded, and it never reaches a column.
 */
final readonly class Device
{
    public function __construct(
        public DeviceType $type = DeviceType::Unknown,
        public Browser $browser = Browser::Unknown,
        public OperatingSystem $os = OperatingSystem::Unknown,
    ) {}

    /**
     * A device that could not be identified.
     */
    public static function unknown(): self
    {
        return new self;
    }

    /**
     * Whether detection produced anything usable.
     */
    public function isKnown(): bool
    {
        return $this->type !== DeviceType::Unknown
            || $this->browser !== Browser::Unknown
            || $this->os !== OperatingSystem::Unknown;
    }
}
