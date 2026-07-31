<?php

declare(strict_types=1);

namespace Divoto\Cairn\Maintenance;

use Divoto\Cairn\Enums\GeoPrecision;
use Divoto\Cairn\Support\Tables;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Audits an installation and reports what it finds.
 *
 * Every finding explains what the setting *means* in plain language and stops
 * there. None of them asserts a legal conclusion, because compliance is a
 * property of a deployment and its context, not of a configuration file — and
 * a package that told you "this is GDPR compliant" would be telling you
 * something it cannot know.
 *
 * Findings are observations, not errors. A deployment can be entirely
 * reasonable with several of them showing.
 */
final readonly class Doctor
{
    public function __construct(
        private Config $config,
        private Application $app,
        private Gate $gate,
    ) {}

    /**
     * Everything worth telling the deployer about.
     *
     * @return list<Finding>
     */
    public function examine(): array
    {
        return array_values(array_filter([
            $this->durableIdentity(),
            $this->userTracking(),
            $this->geoPrecision(),
            $this->retention(),
            $this->openGate(),
            $this->saltStore(),
            $this->sharedRedis(),
            $this->noScheduler(),
            $this->disabled(),
        ]));
    }

    /**
     * The setting that reverses Cairn's central design decision.
     */
    private function durableIdentity(): ?Finding
    {
        if ($this->config->get('cairn.privacy.durable_identity') !== true) {
            return null;
        }

        return new Finding(
            'Durable cookie identity is on',
            'Visitors are recognised across days by a cookie stored on their device, '
            .'rather than by a hash that is regenerated every 24 hours. Cairn is no '
            .'longer cookieless on this deployment, and the statement that it needs no '
            .'consent banner in its default configuration no longer describes it. '
            .'What you gain is accurate returning-visitor counts.',
            'cairn.privacy.durable_identity',
        );
    }

    /**
     * The setting that turns analytics into personal data.
     */
    private function userTracking(): ?Finding
    {
        if ($this->config->get('cairn.privacy.track_user_id') !== true) {
            return null;
        }

        return new Finding(
            'Authenticated user attribution is on',
            'Entries carry the ID of the signed-in user, which points directly at a row '
            .'in your users table. The analytics data is therefore no longer '
            .'unlinkable to a person, and falls inside whatever obligations already '
            .'apply to your user records — retention, access requests, erasure. '
            .'cairn:forget and cairn:export are there to service those.',
            'cairn.privacy.track_user_id',
        );
    }

    /**
     * Location detail beyond country.
     */
    private function geoPrecision(): ?Finding
    {
        $configured = $this->config->get('cairn.privacy.geo_precision');
        $precision = is_string($configured) ? GeoPrecision::tryFrom($configured) : null;

        if ($precision === null || ! $precision->isElevated()) {
            return null;
        }

        return new Finding(
            sprintf('Location is stored at %s level', $precision->value),
            'Each step beyond country narrows the group a visitor hash could belong to. '
            .'A country plus a browser plus a device class describes a very large number '
            .'of people; a city plus the same attributes may describe a handful. Whether '
            .'that matters depends on your traffic volume and who your visitors are.',
            'cairn.privacy.geo_precision',
        );
    }

    /**
     * A raw-data window long enough to be worth a second look.
     */
    private function retention(): ?Finding
    {
        $days = $this->config->get('cairn.retention.entries');

        if ($days === null) {
            return new Finding(
                'Raw entries are kept forever',
                'retention.entries is null, so nothing removes raw visitor-level rows. '
                .'The rollups Cairn reports from are counts and are meant to be '
                .'permanent; the raw table behind them is not, and it will grow without '
                .'bound.',
                'cairn.retention.entries',
            );
        }

        if (! is_numeric($days) || (int) $days <= 780) {
            return null;
        }

        return new Finding(
            sprintf('Raw entries are kept for %d days', (int) $days),
            'That is over 26 months of visitor-level rows. Cairn keeps raw data short '
            .'by default because the rollups are what the dashboard reads — a long raw '
            .'window costs storage and widens what a database compromise would expose, '
            .'without making any report more accurate.',
            'cairn.retention.entries',
        );
    }

    /**
     * A dashboard anybody can reach.
     */
    private function openGate(): ?Finding
    {
        if ($this->app->environment('local')) {
            return null;
        }

        try {
            // No user, outside local: if this passes, the gate lets anonymous
            // visitors read every number on the site.
            if (! $this->gate->forUser(null)->allows('viewCairn', [null])) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return new Finding(
            'The dashboard is reachable without authentication',
            'The viewCairn gate permits an unauthenticated visitor outside the local '
            .'environment. Anybody who finds the URL can read every page, referrer and '
            .'campaign on the site. Define a viewCairn gate that checks who is asking.',
            'viewCairn',
            severe: true,
        );
    }

    /**
     * A salt that outlives its rotation because the cache is on disk.
     */
    private function saltStore(): ?Finding
    {
        $store = $this->config->get('cairn.cache_store');
        $name = is_string($store) && $store !== ''
            ? $store
            : $this->config->get('cache.default');

        if (! is_string($name)) {
            return null;
        }

        $driver = $this->config->get('cache.stores.'.$name.'.driver');

        if (! in_array($driver, ['file', 'database'], true)) {
            return null;
        }

        return new Finding(
            sprintf('The visitor salt is stored on disk (cache store "%s")', $name),
            'Cairn\'s guarantee that yesterday\'s hashes cannot be recomputed depends on '
            .'the salt being genuinely gone after rotation. A file or database cache '
            .'writes it to disk, where a backup may outlive the rotation window. A '
            .'memory-backed store — Redis, Memcached, APCu — forgets it properly. Set '
            .'cairn.cache_store to point at one.',
            'cairn.cache_store',
        );
    }

    /**
     * Cairn sharing a Redis connection with the queue.
     */
    private function sharedRedis(): ?Finding
    {
        if ($this->config->get('cairn.driver') !== 'redis') {
            return null;
        }

        $ours = $this->config->get('cairn.redis.connection');
        $queue = $this->config->get('queue.connections.redis.connection');

        if (! is_string($ours) || ! is_string($queue) || $ours !== $queue) {
            return null;
        }

        return new Finding(
            'Cairn shares a Redis connection with your queue',
            'Flushing the queue would take Cairn\'s buffered entries with it. Point '
            .'cairn.redis.connection at a connection of its own.',
            'cairn.redis.connection',
        );
    }

    /**
     * Maintenance running off the request lottery rather than a scheduler.
     */
    private function noScheduler(): ?Finding
    {
        $lottery = $this->config->get('cairn.ingest.lottery');

        if (! is_array($lottery) || ($lottery[0] ?? 0) <= 0) {
            return null;
        }

        return new Finding(
            'Maintenance runs from the request lottery',
            'A fraction of requests carry the rollup and prune work. That is the '
            .'fallback for hosts with no cron, and it works — but if you are running '
            .'Laravel\'s scheduler, Cairn has already registered its commands there and '
            .'you can set ingest.lottery to [0, 100] to take the work off your requests '
            .'entirely.',
            'cairn.ingest.lottery',
        );
    }

    /**
     * Cairn switched off.
     */
    private function disabled(): ?Finding
    {
        if ($this->config->get('cairn.enabled') === true) {
            return null;
        }

        return new Finding(
            'Cairn is disabled',
            'Nothing is being recorded. Reports will run and return nothing.',
            'cairn.enabled',
            severe: true,
        );
    }

    /**
     * The tables Cairn owns, for the command to report row counts from.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        return Tables::all();
    }
}
