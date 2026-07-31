<?php

declare(strict_types=1);

namespace Divoto\Cairn\Maintenance;

use Carbon\CarbonImmutable;
use Divoto\Cairn\Contracts\Presence;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Contracts\UniqueCounter;
use Divoto\Cairn\Presence\DatabasePresence;
use Divoto\Cairn\Support\Engine;
use Divoto\Cairn\Support\MonthlyPartitions;
use Divoto\Cairn\Support\Tables;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Enforces retention across every table Cairn owns.
 *
 * Raw data is short-lived; rollups are the permanent record and are only
 * removed if the deployer explicitly asks. That asymmetry is the point: a
 * count of how many people visited a page last March is not a record of
 * anybody, while the rows it was computed from are.
 *
 * Where a table has been partitioned, whole months are dropped as a metadata
 * operation. `DROP PARTITION` removes a month of entries instantly; the
 * chunked `DELETE` it replaces rewrites indexes for hours on the same data.
 */
final readonly class Pruner
{
    public function __construct(
        private DatabaseManager $database,
        private Config $config,
        private Storage $storage,
        private UniqueCounter $uniques,
        private Presence $presence,
    ) {}

    /**
     * Prune everything that is past its retention window.
     *
     * @return array<string, int> Rows or partitions removed, per table.
     */
    public function prune(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');

        return [
            'entries' => $this->pruneEntries($now),
            'sessions' => $this->pruneSessions($now),
            'visitor_days' => $this->pruneVisitorDays($now),
            'presence' => $this->prunePresence(),
            'aggregates' => $this->pruneAggregates($now),
        ];
    }

    /**
     * Whether a table is partitioned, and so can be pruned by dropping months.
     */
    public function isPartitioned(string $table): bool
    {
        if (! Engine::supportsPartitioning($this->driver())) {
            return false;
        }

        try {
            $rows = $this->connection()->select(
                'select partition_name from information_schema.partitions '
                .'where table_schema = database() and table_name = ? and partition_name is not null',
                [$table],
            );

            return $rows !== [];
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The partitions on a table that lie entirely before a cutoff.
     *
     * Named `pYYYYMM`, so the month a partition covers is recoverable from its
     * name without another metadata query.
     *
     * @return list<string>
     */
    public function droppablePartitions(string $table, CarbonImmutable $before): array
    {
        try {
            $rows = $this->connection()->select(
                'select partition_name from information_schema.partitions '
                .'where table_schema = database() and table_name = ? and partition_name is not null',
                [$table],
            );
        } catch (Throwable) {
            return [];
        }

        $cutoff = MonthlyPartitions::nameFor($before->startOfMonth());
        $droppable = [];

        foreach ($rows as $row) {
            $name = (array) $row;
            $partition = $name['partition_name'] ?? $name['PARTITION_NAME'] ?? null;

            if (! is_string($partition) || ! str_starts_with($partition, 'p')) {
                continue;
            }

            // The catch-all is never dropped: it is where everything beyond
            // the last boundary lands, and dropping it would discard live
            // data and break the next insert.
            if ($partition === 'pmax') {
                continue;
            }

            if ($partition < $cutoff) {
                $droppable[] = $partition;
            }
        }

        return $droppable;
    }

    /**
     * Remove raw entries past their retention window.
     */
    private function pruneEntries(CarbonImmutable $now): int
    {
        $days = $this->retentionDays('entries');

        if ($days === null) {
            return 0;
        }

        $before = $now->subDays($days);
        $table = Tables::entries();

        $dropped = $this->dropPartitionsBefore($table, $before);

        if ($dropped !== null) {
            return $dropped;
        }

        try {
            return $this->storage->prune($before);
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    private function pruneSessions(CarbonImmutable $now): int
    {
        $days = $this->retentionDays('sessions');

        if ($days === null) {
            return 0;
        }

        $before = $now->subDays($days);
        $table = Tables::sessions();

        $dropped = $this->dropPartitionsBefore($table, $before);

        if ($dropped !== null) {
            return $dropped;
        }

        try {
            return $this->connection()
                ->table($table)
                ->where('started_at', '<', $before->toDateTimeString())
                ->delete();
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * Unique-counter data follows the raw entry window.
     *
     * It is visitor-level data — a hash per person per day — so it cannot
     * outlive the entries it was derived from.
     */
    private function pruneVisitorDays(CarbonImmutable $now): int
    {
        $days = $this->retentionDays('entries');

        if ($days === null) {
            return 0;
        }

        try {
            return $this->uniques->prune($now->subDays($days)->format('Y-m-d'));
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * Presence expires on its own window, not on a retention setting.
     */
    private function prunePresence(): int
    {
        if (! $this->presence instanceof DatabasePresence) {
            return 0;
        }

        return $this->presence->sweep();
    }

    /**
     * Aggregates are kept forever unless retention is explicitly configured.
     *
     * They contain no visitor-level data — there is no hash in the table and
     * nothing in a row points at an individual — so the default is to keep the
     * history rather than to discard it on a schedule nobody chose.
     */
    private function pruneAggregates(CarbonImmutable $now): int
    {
        $days = $this->retentionDays('aggregates');

        if ($days === null) {
            return 0;
        }

        try {
            return $this->connection()
                ->table(Tables::aggregates())
                ->where('bucket', '<', $now->subDays($days)->getTimestamp())
                ->delete();
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * Drop whole months from a partitioned table.
     *
     * Returns null when the table is not partitioned, so the caller falls back
     * to deleting rows.
     */
    private function dropPartitionsBefore(string $table, CarbonImmutable $before): ?int
    {
        if (! $this->isPartitioned($table)) {
            return null;
        }

        $partitions = $this->droppablePartitions($table, $before);

        if ($partitions === []) {
            return 0;
        }

        try {
            $this->connection()->statement(
                sprintf('ALTER TABLE %s DROP PARTITION %s', $table, implode(', ', $partitions))
            );

            return count($partitions);
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * A retention window in days, or null to keep forever.
     */
    private function retentionDays(string $key): ?int
    {
        $days = $this->config->get('cairn.retention.'.$key);

        return is_numeric($days) && (int) $days > 0 ? (int) $days : null;
    }

    private function driver(): string
    {
        return Tables::driver();
    }

    private function connection(): Connection
    {
        return $this->database->connection(Tables::connection());
    }
}
