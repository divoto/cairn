<?php

declare(strict_types=1);

namespace Divoto\Cairn\Presence;

use Carbon\CarbonImmutable;
use Divoto\Cairn\Contracts\Presence;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Tracks current activity in a sorted set scored by last-seen timestamp.
 *
 * The window is enforced by removing everything below the cutoff before each
 * read, so the set stays proportional to concurrent visitors rather than to
 * total traffic — no sweep task is needed.
 *
 * The page a visitor is on lives in a companion hash rather than in the set
 * member, so that a page change updates one field instead of removing and
 * re-adding an entry.
 */
final readonly class RedisPresence implements Presence
{
    private const WINDOW_MINUTES = 5;

    private const SET = 'cairn:presence';

    private const PAGES = 'cairn:presence:pages';

    public function __construct(
        private Redis $redis,
        private Config $config,
    ) {}

    public function touch(string $visitor, ?string $page = null): void
    {
        try {
            $connection = $this->connection();
            $member = bin2hex($visitor);

            $connection->zadd(self::SET, CarbonImmutable::now('UTC')->getTimestamp(), $member);

            if ($page !== null) {
                $connection->hset(self::PAGES, $member, $page);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function count(): int
    {
        try {
            $connection = $this->connection();
            $this->evict($connection);

            return (int) $connection->zcard(self::SET);
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * @return Collection<int, array{visitor: string, page: string|null, last_seen_at: string}>
     */
    public function recent(int $limit = 50): Collection
    {
        try {
            $connection = $this->connection();
            $this->evict($connection);

            /** @var array<string, mixed> $members */
            $members = $connection->zrevrange(self::SET, 0, max(0, $limit - 1), ['withscores' => true]);

            $rows = [];

            foreach ($members as $member => $score) {
                $page = $connection->hget(self::PAGES, (string) $member);

                $rows[] = [
                    'visitor' => (string) $member,
                    'page' => is_string($page) ? $page : null,
                    'last_seen_at' => CarbonImmutable::createFromTimestampUTC(
                        is_numeric($score) ? (int) $score : 0
                    )->toDateTimeString(),
                ];
            }

            return new Collection($rows);
        } catch (Throwable $e) {
            report($e);

            return new Collection;
        }
    }

    /**
     * Remove everything that has fallen out of the window.
     *
     * Called before each read rather than on a timer, so the set is correct
     * whether or not a scheduler is running.
     */
    private function evict(Connection $connection): void
    {
        $cutoff = CarbonImmutable::now('UTC')->subMinutes(self::WINDOW_MINUTES)->getTimestamp();

        /** @var array<int, string> $expired */
        $expired = $connection->zrangebyscore(self::SET, '-inf', (string) $cutoff);

        if ($expired !== []) {
            $connection->hdel(self::PAGES, ...$expired);
        }

        $connection->zremrangebyscore(self::SET, '-inf', (string) $cutoff);
    }

    private function connection(): Connection
    {
        $name = $this->config->get('cairn.redis.connection');

        return $this->redis->connection(is_string($name) && $name !== '' ? $name : null);
    }
}
