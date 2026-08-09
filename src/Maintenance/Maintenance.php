<?php

declare(strict_types=1);

namespace Divoto\Cairn\Maintenance;

use Carbon\CarbonImmutable;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Enums\Period;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

/**
 * Keeps rollups current and old data pruned, without a scheduler.
 *
 * A scheduler is the right way to run maintenance and is what the docs
 * recommend. But Cairn promises to work on a shared host, and plenty of those
 * have no cron at all — so a small fraction of requests carry the work
 * instead. Getting this wrong on a busy site would be expensive, so it is
 * bounded twice over: by a lottery, and by a cache lock that stops two
 * concurrent winners doing the same work.
 *
 * Everything here runs after the response has been sent, and everything is
 * wrapped. Maintenance failing is not the host application's problem.
 */
final readonly class Maintenance
{
    /**
     * How long after a run before another may start.
     *
     * Rolling up the current day every few minutes is enough to keep a
     * dashboard current; doing it on every winning request would mean a busy
     * site paying for the same work over and over.
     */
    private const COOLDOWN_SECONDS = 300;

    /**
     * How long the lock is held, as a backstop if a run dies mid-way.
     */
    private const LOCK_SECONDS = 600;

    public function __construct(
        private Storage $storage,
        private Pruner $pruner,
        private Cache $cache,
        private Config $config,
    ) {}

    /**
     * Run maintenance if the lottery says so and nothing else is running it.
     *
     * @return bool Whether work was actually done.
     */
    public function tick(): bool
    {
        if (! $this->wins()) {
            return false;
        }

        return $this->run();
    }

    /**
     * Roll up today and prune, once, under a lock.
     *
     * @return bool Whether work was actually done.
     */
    public function run(): bool
    {
        // add() returns false when the key already exists, which makes this a
        // lock without needing a lock driver — the database cache store has
        // no atomic locks, and Cairn has to work there.
        if (! $this->cache->add($this->cacheKey(), 1, self::LOCK_SECONDS)) {
            return false;
        }

        try {
            $now = CarbonImmutable::now('UTC');

            // Only today. Yesterday's buckets stopped changing at midnight,
            // and rebuilding them on every tick would be pure waste.
            $this->storage->rollup($now->startOfDay(), $now->endOfDay(), Period::Day);
            $this->storage->rollup($now->startOfHour(), $now->endOfHour(), Period::Hour);
            $this->storage->rollup($now->startOfMonth(), $now->endOfMonth(), Period::Month);

            $this->pruner->prune($now);

            // Replace the lock with a cooldown marker, so the next run is
            // spaced out rather than possible immediately.
            $this->cache->put($this->cacheKey(), 1, self::COOLDOWN_SECONDS);

            return true;
        } catch (Throwable $e) {
            report($e);

            $this->cache->forget($this->cacheKey());

            return false;
        }
    }

    /**
     * Whether this request drew the short straw.
     *
     * Configured as `[chances, out_of]`. Setting chances to 0 disables the
     * fallback entirely, which is what a deployment with a working scheduler
     * should do.
     */
    public function wins(): bool
    {
        $lottery = $this->config->get('cairn.ingest.lottery');

        if (! is_array($lottery) || count($lottery) !== 2) {
            return false;
        }

        $chances = is_numeric($lottery[0] ?? null) ? (int) $lottery[0] : 0;
        $outOf = is_numeric($lottery[1] ?? null) ? (int) $lottery[1] : 0;

        if ($chances <= 0 || $outOf <= 0) {
            return false;
        }

        return random_int(1, $outOf) <= $chances;
    }

    private function cacheKey(): string
    {
        return 'cairn:maintenance';
    }
}
