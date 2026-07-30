<?php

declare(strict_types=1);

namespace Divoto\Cairn\Contracts;

use Illuminate\Support\Collection;

/**
 * Tracks who is on the site right now.
 *
 * Presence is a short sliding window — five minutes by default — over the most
 * recent activity. It is the one part of Cairn that is genuinely realtime, and
 * the only part whose data is expected to disappear on its own.
 *
 * Implementations store the visitor hash and, optionally, a page. Nothing
 * else. A live-visitor list is a place where a small dataset plus a rich
 * record would identify individuals, so the record stays thin.
 */
interface Presence
{
    /**
     * Mark a visitor as active now, optionally on a given page.
     *
     * @param  string  $visitor  The raw 16-byte daily-rotating visitor hash.
     */
    public function touch(string $visitor, ?string $page = null): void;

    /**
     * How many distinct visitors are active within the window.
     */
    public function count(): int;

    /**
     * The most recently active visitors, newest first.
     *
     * @return Collection<int, array{visitor: string, page: string|null, last_seen_at: string}>
     */
    public function recent(int $limit = 50): Collection;
}
