<?php

declare(strict_types=1);

namespace Divoto\Cairn\Commands;

use Divoto\Cairn\Contracts\Ingest;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Ingest\RedisIngest;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Drain the Redis ingest list into storage.
 *
 * Only needed on the `redis` driver — the database driver flushes from the
 * request's own terminating callback and has nothing to drain.
 *
 * Shuts down gracefully. On SIGTERM or SIGINT it finishes the batch it is
 * holding and then stops, because a batch abandoned mid-write is a batch that
 * gets replayed and double-counted.
 */
final class WorkCommand extends Command
{
    protected $signature = 'cairn:work
        {--sleep=1 : Seconds to wait when the queue is empty.}
        {--max-batches=0 : Stop after this many batches. 0 runs until stopped.}';

    protected $description = 'Drain the Redis ingest queue into storage';

    /**
     * Set by a signal handler; checked between batches.
     */
    private bool $shouldStop = false;

    public function handle(Ingest $ingest, Storage $storage, Config $config): int
    {
        if (! $ingest instanceof RedisIngest) {
            $this->components->warn(sprintf(
                'cairn:work is only needed on the redis driver; cairn.driver is "%s". '
                .'The database driver flushes from the request itself.',
                is_string($config->get('cairn.driver')) ? $config->get('cairn.driver') : 'unknown',
            ));

            return self::SUCCESS;
        }

        if ($ingest->sharesConnectionWithQueue()) {
            $this->components->warn(
                'Cairn is using the same Redis connection as your queue. '
                .'Flushing the queue would take buffered entries with it — '
                .'set cairn.redis.connection to a connection of its own.'
            );
        }

        $this->listenForShutdown();

        $sleep = max(0, (int) $this->option('sleep'));
        $maxBatches = max(0, (int) $this->option('max-batches'));

        $batches = 0;
        $total = 0;

        $this->components->info('Draining '.RedisIngest::QUEUE.'. Press Ctrl+C to stop.');

        while (! $this->shouldStop) {
            $stored = $ingest->digest($storage);
            $batches++;
            $total += $stored;

            if ($maxBatches > 0 && $batches >= $maxBatches) {
                break;
            }

            if ($stored === 0) {
                if ($sleep === 0) {
                    break;
                }

                // Only sleep when there is nothing to do, so a backlog drains
                // at full speed.
                sleep($sleep);
            }
        }

        $this->components->info(sprintf('Stopped after storing %d entries.', $total));

        return self::SUCCESS;
    }

    /**
     * Stop cleanly on SIGTERM or SIGINT.
     *
     * The flag is checked between batches rather than acted on immediately, so
     * a batch that has already been read from Redis is written before exit.
     * Without pcntl the command still works; it just stops on Ctrl+C the blunt
     * way, which may replay one batch.
     */
    private function listenForShutdown(): void
    {
        // @codeCoverageIgnoreStart
        // ext-pcntl ships in every environment this package's test suite runs
        // in, so the branch guarding its absence has no path a test can reach.
        if (! function_exists('pcntl_signal') || ! function_exists('pcntl_async_signals')) {
            return;
        }
        // @codeCoverageIgnoreEnd

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->shouldStop = true;
            });
        }
    }
}
