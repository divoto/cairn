<?php

declare(strict_types=1);

namespace Divoto\Cairn\Support;

use Carbon\CarbonImmutable;

/**
 * Builds the `ALTER TABLE` that installs monthly range partitions.
 *
 * Kept separate from the command that runs it so the generated SQL can be
 * asserted on any engine, including SQLite in the default test suite. A
 * statement builder that could only be tested on the engine it targets would
 * be tested rarely and wrongly.
 */
final class MonthlyPartitions
{
    /**
     * Build the statement partitioning a table by month on a time column.
     *
     * Uses `RANGE COLUMNS` rather than `RANGE` with a function so the
     * boundaries read as dates and the column keeps its natural type.
     *
     * @param  int  $months  How many monthly partitions to create.
     * @param  CarbonImmutable  $from  The month to start at; anything earlier lands in the first partition.
     */
    public static function statement(
        string $table,
        string $column,
        int $months,
        CarbonImmutable $from,
    ): string {
        $start = $from->startOfMonth();
        $months = max(1, $months);

        $definitions = [];

        for ($offset = 0; $offset < $months; $offset++) {
            $definitions[] = sprintf(
                "PARTITION %s VALUES LESS THAN ('%s')",
                self::nameFor($start->addMonths($offset)),
                $start->addMonths($offset + 1)->format('Y-m-d'),
            );
        }

        // A catch-all so rows beyond the final boundary remain insertable.
        // Without it the first write past the last partition fails outright,
        // which would stop analytics dead on a date nobody was watching for.
        $definitions[] = 'PARTITION pmax VALUES LESS THAN (MAXVALUE)';

        return sprintf(
            'ALTER TABLE %s PARTITION BY RANGE COLUMNS(%s) (%s)',
            $table,
            $column,
            implode(', ', $definitions),
        );
    }

    /**
     * The partition name for a month, e.g. `p202601`.
     */
    public static function nameFor(CarbonImmutable $month): string
    {
        return 'p'.$month->format('Ym');
    }
}
