<?php

declare(strict_types=1);

namespace Divoto\Cairn\Commands;

use Carbon\CarbonImmutable;
use Divoto\Cairn\Support\Engine;
use Divoto\Cairn\Support\MonthlyPartitions;
use Divoto\Cairn\Support\Tables;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Convert the raw tables to monthly range partitions.
 *
 * Deliberately not part of the default migration. SQLite cannot partition at
 * all, PostgreSQL's declarative partitioning is a different mechanism rather
 * than different syntax, and plenty of MySQL deployments — shared hosting in
 * particular — lack the privilege. Making it a migration would mean a fresh
 * install failing for a large class of users on a feature none of them asked
 * for yet.
 *
 * What partitioning buys is pruning: `DROP PARTITION` removes a month of raw
 * entries as a metadata operation, where a chunked `DELETE` on the same data
 * rewrites indexes for hours.
 */
final class PartitionCommand extends Command
{
    protected $signature = 'cairn:partition
        {--months=13 : How many monthly partitions to create, counting forward from the oldest data}
        {--pretend : Print the statements without running them}';

    protected $description = "Convert Cairn's raw tables to monthly range partitions (MySQL and MariaDB only)";

    public function handle(DatabaseManager $database): int
    {
        $driver = Tables::driver();

        if (! Engine::supportsPartitioning($driver)) {
            $this->components->warn(sprintf(
                'Partitioning is not available on %s. Cairn works fully without it — '.
                'cairn:prune falls back to chunked deletes, which are slower but correct.',
                $driver,
            ));

            return self::SUCCESS;
        }

        $months = max(1, (int) $this->option('months'));
        $pretend = (bool) $this->option('pretend');

        $connection = $database->connection(Tables::connection());

        $from = CarbonImmutable::now('UTC');

        foreach ($this->targets() as $table => $column) {
            $statement = MonthlyPartitions::statement($table, $column, $months, $from);

            if ($pretend) {
                $this->line($statement.';');

                continue;
            }

            try {
                $connection->statement($statement);
                $this->components->info("Partitioned {$table} into {$months} monthly partitions.");
            } catch (Throwable $e) {
                // Most commonly a missing privilege, or a table that is
                // already partitioned. Neither is worth a stack trace, and
                // neither should stop the other table being converted.
                $this->components->error("Could not partition {$table}: ".$e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    /**
     * The tables to partition, mapped to the time column they partition on.
     *
     * Both already carry that column in their primary key — MySQL requires
     * every unique key to contain the partitioning column, and the migrations
     * create them that way from the start so this command never has to
     * rebuild a large table's primary key.
     *
     * @return array<string, string>
     */
    private function targets(): array
    {
        return [
            Tables::entries() => 'occurred_at',
            Tables::sessions() => 'started_at',
        ];
    }
}
