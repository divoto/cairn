<?php

declare(strict_types=1);

namespace Divoto\Cairn\Commands;

use Divoto\Cairn\Maintenance\Pruner;
use Divoto\Cairn\Support\Tables;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Enforce retention across Cairn's tables.
 *
 * Raw data goes; rollups stay. On a partitioned table whole months are dropped
 * as a metadata operation rather than deleted row by row.
 */
final class PruneCommand extends Command
{
    protected $signature = 'cairn:prune';

    protected $description = 'Delete Cairn data that is past its retention window';

    public function handle(Pruner $pruner, Config $config): int
    {
        $removed = $pruner->prune();

        foreach ($removed as $table => $count) {
            $this->line(sprintf(
                '  %s %s',
                str_pad(Tables::name($table), 24),
                $this->describe($table, $count, $pruner),
            ));
        }

        if ($config->get('cairn.retention.aggregates') === null) {
            $this->newLine();
            $this->components->info(
                'Rollups were kept. They hold counts rather than records of people, '
                .'so retention.aggregates defaults to keeping them forever.'
            );
        }

        return self::SUCCESS;
    }

    /**
     * Describe what happened to a table, in the units that actually applied.
     */
    private function describe(string $table, int $count, Pruner $pruner): string
    {
        if ($count === 0) {
            return 'nothing to remove';
        }

        if (in_array($table, ['entries', 'sessions'], true) && $pruner->isPartitioned(Tables::name($table))) {
            return sprintf('%d partition%s dropped', $count, $count === 1 ? '' : 's');
        }

        return sprintf('%d row%s removed', $count, $count === 1 ? '' : 's');
    }
}
