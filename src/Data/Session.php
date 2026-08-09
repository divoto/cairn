<?php

declare(strict_types=1);

namespace Divoto\Cairn\Data;

use Carbon\CarbonImmutable;

/**
 * A visit: one run of activity by one visitor hash.
 *
 * A session ends after 30 minutes of inactivity. It also cannot meaningfully
 * outlive the salt that produced its visitor hash — after rotation the same
 * person is a different visitor, so the old session is closed rather than
 * continued.
 */
final readonly class Session
{
    /**
     * @param  string  $id  Raw 16 bytes, derived from the visitor and the start instant.
     * @param  string  $visitor  The raw 16-byte visitor hash.
     * @param  bool  $isNew  True when this call created the session.
     */
    public function __construct(
        public string $id,
        public string $visitor,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $lastActivityAt,
        public int $pageCount = 0,
        public bool $isNew = true,
        public int|string|null $tenantId = null,
    ) {}

    /**
     * Whether this visit consists of a single page.
     *
     * Recomputed from `page_count` on every write rather than being decided
     * once, because a session only stops being a bounce when a second page
     * arrives — which may be half an hour after the first.
     */
    public function isBounce(): bool
    {
        return $this->pageCount <= 1;
    }

    /**
     * How long the visit has lasted, in whole seconds.
     */
    public function durationSeconds(): int
    {
        return max(0, (int) $this->startedAt->diffInSeconds($this->lastActivityAt, absolute: true));
    }
}
