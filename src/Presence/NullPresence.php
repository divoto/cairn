<?php

declare(strict_types=1);

namespace Divoto\Cairn\Presence;

use Divoto\Cairn\Contracts\Presence;
use Illuminate\Support\Collection;

/**
 * A presence driver that never sees anyone.
 *
 * Bound when `cairn.enabled` is false.
 */
final class NullPresence implements Presence
{
    public function touch(string $visitor, ?string $page = null): void
    {
        // Deliberately empty.
    }

    public function count(): int
    {
        return 0;
    }

    /**
     * @return Collection<int, array{visitor: string, page: string|null, last_seen_at: string}>
     */
    public function recent(int $limit = 50): Collection
    {
        return new Collection;
    }
}
