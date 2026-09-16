<?php

declare(strict_types=1);

namespace Divoto\Cairn\Counting;

use Divoto\Cairn\Contracts\UniqueCounter;
use Divoto\Cairn\Support\CountsUniquesInBulk;

/**
 * A unique counter that counts nothing.
 *
 * Bound when `cairn.enabled` is false.
 */
final class NullUniqueCounter implements CountsUniquesInBulk, UniqueCounter
{
    public function add(string $day, string $dimension, string $visitor): void
    {
        // Deliberately empty.
    }

    public function count(string $day, string $dimension): int
    {
        return 0;
    }

    public function counts(array $days, array $dimensions): array
    {
        return array_fill_keys($dimensions, array_fill_keys($days, 0));
    }

    public function prune(string $beforeDay): int
    {
        return 0;
    }
}
