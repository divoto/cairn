<?php

declare(strict_types=1);

namespace Divoto\Cairn\Commands;

use Carbon\CarbonImmutable;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Enums\Period;
use Divoto\Cairn\Support\Buckets;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Recompute `cairn_aggregates` for a window.
 *
 * This is the authoritative repair path. Aggregates are also written as
 * entries are digested, which keeps the dashboard current between runs, but
 * that path can drift — a crash between writing entries and updating
 * aggregates, a replayed Redis batch, a clock skew. Running this command over
 * the affected window fixes it, because it deletes and rebuilds rather than
 * incrementing.
 *
 * That makes it safe to run at any time, over any window, as often as you
 * like: the same input always produces the same rows.
 */
final class RollupCommand extends Command
{
    protected $signature = 'cairn:rollup
        {--date= : A single day to recompute, as YYYY-MM-DD. Defaults to today.}
        {--from= : Start of a window to recompute, as YYYY-MM-DD.}
        {--to= : End of a window to recompute, as YYYY-MM-DD. Defaults to today.}
        {--period=day : Which buckets to rebuild: hour, day, month, or all.}';

    protected $description = 'Recompute Cairn rollups for a window';

    public function handle(Storage $storage): int
    {
        try {
            [$from, $to] = $this->window();
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $periods = $this->periods();

        if ($periods === []) {
            $this->components->error(
                'Unknown period. Use one of: hour, day, month, all.'
            );

            return self::FAILURE;
        }

        foreach ($periods as $period) {
            $buckets = count(Buckets::between($from, $to, $period));

            $written = $storage->rollup($from, $to, $period);

            $this->components->info(sprintf(
                'Rebuilt %d %s bucket%s from %s to %s (%d aggregate rows).',
                $buckets,
                $period->value,
                $buckets === 1 ? '' : 's',
                $from->toDateString(),
                $to->toDateString(),
                $written,
            ));
        }

        return self::SUCCESS;
    }

    /**
     * The window to recompute.
     *
     * `--date` is shorthand for a single day. Otherwise `--from` and `--to`
     * bound the window, both defaulting to today, so a bare `cairn:rollup`
     * rebuilds today — which is what a scheduler wants.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function window(): array
    {
        $date = $this->option('date');

        if (is_string($date) && $date !== '') {
            $day = $this->parse($date);

            return [$day->startOfDay(), $day->endOfDay()];
        }

        $from = $this->option('from');
        $to = $this->option('to');

        $start = is_string($from) && $from !== ''
            ? $this->parse($from)->startOfDay()
            : CarbonImmutable::now('UTC')->startOfDay();

        $end = is_string($to) && $to !== ''
            ? $this->parse($to)->endOfDay()
            : CarbonImmutable::now('UTC')->endOfDay();

        if ($end->lessThan($start)) {
            throw new InvalidArgumentException('--to is before --from.');
        }

        return [$start, $end];
    }

    /**
     * Which bucket sizes to rebuild.
     *
     * @return list<Period>
     */
    private function periods(): array
    {
        $period = $this->option('period');
        $period = is_string($period) ? strtolower(trim($period)) : 'day';

        if ($period === 'all') {
            return [Period::Hour, Period::Day, Period::Month];
        }

        $resolved = Period::tryFrom($period);

        return $resolved === null ? [] : [$resolved];
    }

    private function parse(string $date): CarbonImmutable
    {
        $parsed = CarbonImmutable::parse($date, 'UTC');

        return $parsed->setTimezone('UTC');
    }
}
