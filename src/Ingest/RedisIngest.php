<?php

declare(strict_types=1);

namespace Divoto\Cairn\Ingest;

use Divoto\Cairn\Contracts\Ingest;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Data\Entry;
use Divoto\Cairn\Support\EntryMapper;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Pushes entries onto a Redis list, drained by `cairn:work`.
 *
 * The trade against {@see DatabaseIngest} is throughput, and nothing else.
 * Both drivers record the same entries and produce the same numbers — the
 * driver-parity suite runs the identical assertions against both, and a
 * behavioural difference between them is a bug rather than a feature.
 *
 * Redis remains optional forever. This driver exists because some deployments
 * have Redis already, not because Cairn needs it.
 */
final readonly class RedisIngest implements Ingest
{
    /**
     * The list entries are pushed onto.
     */
    public const QUEUE = 'cairn:ingest';

    public function __construct(
        private Redis $redis,
        private Config $config,
    ) {}

    public function record(Entry $entry): void
    {
        try {
            // rpush is variadic in Laravel's Redis connections: passing an
            // array here would push the literal string "Array".
            $this->connection()->rpush(self::QUEUE, EntryMapper::serialise($entry));
        } catch (Throwable $e) {
            // A Redis outage must not become the host application's outage.
            report($e);
        }
    }

    /**
     * Drain a batch from the list into storage.
     *
     * Reads with `LRANGE` and then trims, rather than popping one at a time:
     * one round trip per batch instead of one per entry.
     *
     * The trim happens only after the write succeeds. A crash between the two
     * replays a batch — duplicate raw entries, which the rollup will
     * over-count — but the alternative loses entries outright, and a rollup
     * can be recomputed from raw data while lost data is simply gone.
     */
    public function digest(Storage $storage): int
    {
        try {
            $connection = $this->connection();
            $batch = $this->batchSize();

            /** @var array<int, string> $payloads */
            $payloads = $connection->lrange(self::QUEUE, 0, $batch - 1);

            if ($payloads === []) {
                return 0;
            }

            $entries = [];

            foreach ($payloads as $payload) {
                $entry = EntryMapper::deserialise($payload);

                // A single corrupt payload must not stop the worker draining
                // the rest of the queue.
                if ($entry instanceof Entry) {
                    $entries[] = $entry;
                }
            }

            if ($entries !== []) {
                /** @var Collection<int, Entry> $collection */
                $collection = new Collection($entries);
                $storage->store($collection);
            }

            $connection->ltrim(self::QUEUE, count($payloads), -1);

            return count($entries);
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    public function trim(): void
    {
        try {
            $this->connection()->del(self::QUEUE);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * How many entries are waiting to be drained.
     */
    public function pending(): int
    {
        try {
            return (int) $this->connection()->llen(self::QUEUE);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Whether Cairn is sharing a Redis connection with the queue.
     *
     * Flushing the queue would take Cairn's buffered entries with it. The
     * service provider warns about this at boot rather than discovering it
     * during an incident.
     */
    public function sharesConnectionWithQueue(): bool
    {
        $ours = $this->config->get('cairn.redis.connection');
        $queue = $this->config->get('queue.connections.redis.connection');

        return is_string($ours) && is_string($queue) && $ours === $queue;
    }

    private function connection(): Connection
    {
        $name = $this->config->get('cairn.redis.connection');

        return $this->redis->connection(is_string($name) && $name !== '' ? $name : null);
    }

    private function batchSize(): int
    {
        $size = $this->config->get('cairn.ingest.buffer');

        return is_numeric($size) ? max(1, (int) $size) : 500;
    }
}
