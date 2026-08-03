<?php

declare(strict_types=1);

namespace Divoto\Cairn\Counting;

use Divoto\Cairn\Contracts\UniqueCounter;

/**
 * A unique counter that counts nothing.
 *
 * Bound when `cairn.enabled` is false.
 */
final class NullUniqueCounter implements UniqueCounter
{
    public function add(string $day, string $dimension, string $visitor): void
    {
        // Deliberately empty.
    }

    public function count(string $day, string $dimension): int
    {
        return 0;
    }

    public function prune(string $beforeDay): int
    {
        return 0;
    }
}
