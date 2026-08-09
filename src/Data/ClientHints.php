<?php

declare(strict_types=1);

namespace Divoto\Cairn\Data;

/**
 * The subset of User-Agent Client Hints Cairn is willing to look at.
 *
 * Only low-entropy hints are read, and only to improve device classification.
 * Cairn never requests high-entropy hints via `Accept-CH` — asking the browser
 * for model, full version list or architecture would make fingerprinting
 * easier, which is the opposite of the point.
 *
 * None of these values are stored. They feed {@see Device} detection and are
 * then discarded with the request.
 */
final readonly class ClientHints
{
    /**
     * @param  string|null  $platform  Sec-CH-UA-Platform, e.g. "macOS".
     * @param  bool|null  $mobile  Sec-CH-UA-Mobile, the ?1 / ?0 boolean.
     * @param  string|null  $brand  The significant brand from Sec-CH-UA.
     */
    public function __construct(
        public ?string $platform = null,
        public ?bool $mobile = null,
        public ?string $brand = null,
    ) {}

    /**
     * No hints were sent, or the request had none to read.
     */
    public static function none(): self
    {
        return new self;
    }

    /**
     * Whether any hint at all was present.
     */
    public function isEmpty(): bool
    {
        return $this->platform === null
            && $this->mobile === null
            && $this->brand === null;
    }
}
