<?php

declare(strict_types=1);

namespace Divoto\Cairn\Ingest;

use Divoto\Cairn\Contracts\Ingest;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Data\Entry;

/**
 * An ingest driver that discards everything.
 *
 * Bound when `cairn.enabled` is false, and used in tests that need Cairn
 * present but silent. Recording through this driver is not an error — it is
 * the configured behaviour — so it reports nothing and counts nothing.
 */
final class NullIngest implements Ingest
{
    public function record(Entry $entry): void
    {
        // Deliberately empty.
    }

    public function digest(Storage $storage): int
    {
        return 0;
    }

    public function trim(): void
    {
        // Deliberately empty.
    }
}
