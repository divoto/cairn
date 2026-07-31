<?php

declare(strict_types=1);

namespace Divoto\Cairn\Counting;

use Divoto\Cairn\Contracts\UniqueCounter;
use Divoto\Cairn\Support\Tables;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Counts distinct visitors per day using a row per (day, dimension, visitor).
 *
 * Exact rather than estimated, which is the one place this driver beats its
 * Redis counterpart. The cost is a row per visitor per dimension per day, and
 * a rejected insert on every repeat pageview.
 *
 * `insertOrIgnore` rather than read-then-write: the hundredth pageview of the
 * day costs one rejected insert instead of a select followed by an insert, and
 * two concurrent first pageviews cannot both decide the visitor is new.
 */
final readonly class DatabaseUniqueCounter implements UniqueCounter
{
    public function __construct(
        private DatabaseManager $database,
    ) {}

    public function add(string $day, string $dimension, string $visitor): void
    {
        try {
            $this->connection()->table(Tables::visitorDays())->insertOrIgnore([
                'day' => $day,
                'dimension_hash' => $this->hash($dimension),
                'visitor' => $visitor,
                'tenant_id' => '',
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function count(string $day, string $dimension): int
    {
        try {
            return $this->connection()
                ->table(Tables::visitorDays())
                ->where('day', $day)
                ->where('dimension_hash', $this->hash($dimension))
                ->count();
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    public function prune(string $beforeDay): int
    {
        try {
            return $this->connection()
                ->table(Tables::visitorDays())
                ->where('day', '<', $beforeDay)
                ->delete();
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * Hash the dimension key.
     *
     * Stored as a hash rather than as text so this table never holds a
     * readable dimension alongside a visitor hash — a row saying "this visitor
     * read /salary-negotiation" is a more sensitive record than a count of the
     * people who did.
     */
    private function hash(string $dimension): string
    {
        return substr(hash('sha256', $dimension, true), 0, 16);
    }

    private function connection(): Connection
    {
        return $this->database->connection(Tables::connection());
    }
}
