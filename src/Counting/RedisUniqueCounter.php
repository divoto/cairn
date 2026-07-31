<?php

declare(strict_types=1);

namespace Divoto\Cairn\Counting;

use Divoto\Cairn\Contracts\UniqueCounter;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Redis\Connections\Connection;
use Throwable;

/**
 * Counts distinct visitors per day with HyperLogLog.
 *
 * A HyperLogLog holds a fixed 12KB regardless of how many visitors it has
 * seen, where the database driver stores a row each. The cost is that the
 * count is an estimate, accurate to about 0.81% — for a page with 10,000
 * visitors, expect to be told somewhere between 9,919 and 10,081.
 *
 * That inaccuracy is invisible next to the effect of daily salt rotation,
 * which is the dominant reason Cairn's unique counts differ from a
 * cookie-based tool's. Both drivers count the same thing and are covered by
 * the same suite.
 *
 * Keys expire on their own, so this driver's prune has almost nothing to do.
 */
final readonly class RedisUniqueCounter implements UniqueCounter
{
    /**
     * How long a day's counter is kept.
     *
     * Long enough to report on last month, short enough that a counter nobody
     * asked about disappears without a maintenance task.
     */
    private const TTL_SECONDS = 60 * 60 * 24 * 40;

    /**
     * The set of days that have counters, so pruning never needs SCAN.
     */
    private const DAYS = 'cairn:uniques:days';

    public function __construct(
        private Redis $redis,
        private Config $config,
    ) {}

    public function add(string $day, string $dimension, string $visitor): void
    {
        try {
            $key = $this->key($day, $dimension);
            $connection = $this->connection();

            $connection->pfadd($key, [$visitor]);
            $connection->expire($key, self::TTL_SECONDS);

            // An index of which counters exist for which day, so pruning can
            // find them without SCAN. Scanning would work, but it walks the
            // whole keyspace on a server Cairn is only a guest on — and the
            // keys it returns carry the connection prefix, which would then be
            // applied a second time on delete.
            $connection->sadd(self::DAYS, $day);
            $connection->sadd($this->index($day), $key);
            $connection->expire($this->index($day), self::TTL_SECONDS);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function count(string $day, string $dimension): int
    {
        try {
            return (int) $this->connection()->pfcount($this->key($day, $dimension));
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * Remove counters for days before the given one.
     *
     * Redis expires these on its own, so this exists to honour the contract
     * and to let a deployer shorten retention below the TTL.
     */
    public function prune(string $beforeDay): int
    {
        try {
            $connection = $this->connection();
            $removed = 0;

            /** @var array<int, string> $days */
            $days = $connection->smembers(self::DAYS);

            foreach ($days as $day) {
                if ($day >= $beforeDay) {
                    continue;
                }

                /** @var array<int, string> $keys */
                $keys = $connection->smembers($this->index($day));

                foreach ($keys as $key) {
                    $connection->del($key);
                    $removed++;
                }

                $connection->del($this->index($day));
                $connection->srem(self::DAYS, $day);
            }

            return $removed;
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * The key a day's counter for a dimension lives under.
     *
     * The dimension is hashed for the same reason the database driver hashes
     * it: a key naming both a page and the people who read it is a more
     * sensitive record than a count.
     */
    private function key(string $day, string $dimension): string
    {
        return 'cairn:uniques:'.$day.':'.substr(hash('sha256', $dimension), 0, 32);
    }

    /**
     * The set holding every counter key for a day.
     */
    private function index(string $day): string
    {
        return 'cairn:uniques:index:'.$day;
    }

    private function connection(): Connection
    {
        $name = $this->config->get('cairn.redis.connection');

        return $this->redis->connection(is_string($name) && $name !== '' ? $name : null);
    }
}
