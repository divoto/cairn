<?php

declare(strict_types=1);

namespace Divoto\Cairn\Counting;

use Divoto\Cairn\Contracts\UniqueCounter;
use Divoto\Cairn\Support\Binary;
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
            $connection = $this->connection();

            $connection->table(Tables::visitorDays())->insertOrIgnore([
                'day' => $day,
                'dimension_hash' => Binary::bind($connection, $this->hash($dimension)),
                'visitor' => Binary::bind($connection, $visitor),
                'tenant_id' => '',
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function count(string $day, string $dimension): int
    {
        try {
            $connection = $this->connection();

            return $connection
                ->table(Tables::visitorDays())
                ->where('day', $day)
                ->where('dimension_hash', Binary::bind($connection, $this->hash($dimension)))
                ->count();
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    public function counts(array $days, array $dimensions): array
    {
        $counts = $this->zeroed($days, $dimensions);

        if ($days === [] || $dimensions === []) {
            return $counts;
        }

        try {
            $connection = $this->connection();

            // One grouped read for the whole grid. The primary key is
            // (day, dimension_hash, visitor, tenant_id), so this is the same
            // index range the per-day query walked — just walked once.
            $rows = $connection
                ->table(Tables::visitorDays())
                ->selectRaw('day, dimension_hash, count(*) as aggregate')
                ->whereIn('day', $days)
                ->whereIn('dimension_hash', array_map(
                    fn (string $dimension): mixed => Binary::bind($connection, $this->hash($dimension)),
                    $dimensions,
                ))
                ->groupBy('day', 'dimension_hash')
                ->get();

            // The hash comes back in whatever shape the driver stores it —
            // a binary string on MySQL and SQLite, a stream on PostgreSQL —
            // so rows are matched back by hashing the requested keys rather
            // than by comparing what the database returned.
            $byHash = [];

            foreach ($dimensions as $dimension) {
                $byHash[bin2hex($this->hash($dimension))] = $dimension;
            }

            foreach ($rows as $row) {
                $data = (array) $row;

                $hash = Binary::read($data['dimension_hash'] ?? null);
                $dimension = $byHash[bin2hex($hash)] ?? null;

                if ($dimension === null) {
                    continue;
                }

                $day = $this->day($data['day'] ?? null);

                if (! array_key_exists($day, $counts[$dimension])) {
                    continue;
                }

                $value = $data['aggregate'] ?? 0;

                $counts[$dimension][$day] = (int) (is_numeric($value) ? $value : 0);
            }

            return $counts;
        } catch (Throwable $e) {
            report($e);

            return $counts;
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
     * A zero for every requested pair.
     *
     * Built up front so a caller reading the result never has to tell "no
     * visitors" apart from "this pair was not in the grouped result", and so
     * a failed query degrades to zeroes rather than to missing keys.
     *
     * @param  list<string>  $days
     * @param  list<string>  $dimensions
     * @return array<string, array<string, int>>
     */
    private function zeroed(array $days, array $dimensions): array
    {
        $zeroes = array_fill_keys($days, 0);

        return array_fill_keys($dimensions, $zeroes);
    }

    /**
     * Normalise a stored day back to `Y-m-d`.
     *
     * The column is a DATE, which PostgreSQL and MySQL hand back as
     * `Y-m-d` but SQLite returns however it was written — including with a
     * time component when the driver widened it.
     */
    private function day(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return substr($value, 0, 10);
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
