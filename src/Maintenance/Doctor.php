<?php

declare(strict_types=1);

namespace Divoto\Cairn\Maintenance;

use Divoto\Cairn\Enums\GeoPrecision;
use Divoto\Cairn\Enums\RouteGrouping;
use Divoto\Cairn\Geo\MaxMindGeoResolver;
use Divoto\Cairn\Recorders\PageViews;
use Divoto\Cairn\Support\Tables;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Relations\Relation;
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
            $this->subjectIsUser(),
            $this->geoPrecision(),
            $this->geoDatabase(),
            $this->routeGrouping(),
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
     * Views tracked on the application's own user model.
     *
     * `trackView()` on a User puts user ids into aggregate keys, which is the
     * one way the Top content panel turns a table of content into a record
     * about people — and aggregates are the part of Cairn that is meant to
     * outlive raw retention.
     */
    private function subjectIsUser(): ?Finding
    {
        $user = $this->userModel();

        if ($user === null || ! $this->hasSubjectType($user)) {
            return null;
        }

        return new Finding(
            'Views are recorded against your user model',
            'Entries carry '.$user.' as a subject, so its ids appear in aggregate keys '
            .'and on the Top content panel. Aggregates are kept far longer than raw '
            .'entries and are not covered by cairn:forget, so this is a record of what '
            .'individual people did that outlives the retention window. Tracking views '
            .'of content is what this feature is for; tracking views of people is a '
            .'different thing to have decided on purpose.',
            'HasAnalytics on '.$user,
            severe: true,
        );
    }

    /**
     * The application's configured authentication model, if it has one.
     */
    private function userModel(): ?string
    {
        $guard = $this->config->get('auth.defaults.guard');
        $provider = is_string($guard) ? $this->config->get('auth.guards.'.$guard.'.provider') : null;
        $model = is_string($provider) ? $this->config->get('auth.providers.'.$provider.'.model') : null;

        return is_string($model) && $model !== '' ? $model : null;
    }

    /**
     * Whether any entry was recorded against a given class.
     *
     * Matched against the morph alias as well as the class name, since that is
     * what a subject is stored as when a morph map is registered.
     */
    private function hasSubjectType(string $class): bool
    {
        $alias = array_search($class, Relation::morphMap(), true);

        $types = array_values(array_unique(array_filter(
            [$class, is_string($alias) ? $alias : null],
            static fn (?string $value): bool => $value !== null,
        )));

        try {
            return $this->app->make(DatabaseManager::class)
                ->connection(Tables::connection())
                ->table(Tables::entries())
                ->whereIn('subject_type', $types)
                ->exists();
            // @codeCoverageIgnoreStart
            // A doctor that cannot reach the database has nothing to say about
            // what is in it, and must not take the command down saying so.
        } catch (Throwable $e) {
            report($e);

            return false;
        }
        // @codeCoverageIgnoreEnd
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
     * Geo configured but not actually working.
     *
     * The failure mode this catches is quiet: the Countries panel stays empty
     * forever and nothing says why. A missing file is far more common than a
     * wrong one, because the database is downloaded separately and is easy to
     * forget after a deploy.
     */
    private function geoDatabase(): ?Finding
    {
        $resolver = $this->config->get('cairn.privacy.geo_resolver');

        if (! is_string($resolver) || ! is_a($resolver, MaxMindGeoResolver::class, true)) {
            return null;
        }

        $maxmind = new MaxMindGeoResolver($this->config);

        // @codeCoverageIgnoreStart
        // A true here needs a database MaxMind's binary Reader accepts —
        // this suite fakes that reader everywhere else rather than
        // constructing one (see GeolocationTest), and does the same here by
        // not exercising this branch at all.
        if ($maxmind->isAvailable()) {
            return null;
        }
        // @codeCoverageIgnoreEnd

        return new Finding(
            'The MaxMind database is configured but cannot be read',
            sprintf(
                'privacy.geo_resolver points at the MaxMind resolver, but %s. Until '
                .'that is fixed no location is recorded and the Countries panel stays '
                .'empty. Run `php artisan cairn:geoip` to download the database, or '
                .'set CAIRN_GEO_DATABASE to where you have already put it.',
                $maxmind->databasePath() === null
                    ? 'privacy.geo_database is not set'
                    : sprintf('"%s" is missing or unreadable', $maxmind->databasePath()),
            ),
            'cairn.privacy.geo_database',
            severe: true,
        );
    }

    /**
     * Route grouping with no ceiling on how many rows it can produce.
     *
     * This is a legitimate choice — a content site needs it to see anything at
     * all — but it changes the growth characteristics of the rollup table from
     * "bounded by the route table" to "bounded by what visitors ask for",
     * including URLs that never matched a route. Worth knowing before it shows
     * up as disk.
     */
    private function routeGrouping(): ?Finding
    {
        $grouping = RouteGrouping::fromConfig(
            $this->config->get('cairn.recorders.'.PageViews::class.'.group_by'),
        );

        if (! $grouping->isUnbounded()) {
            return null;
        }

        return new Finding(
            'Pageviews are grouped by URL path',
            'Every distinct path is its own row in the routes rollup, rather than every '
            .'route. Identifier segments still collapse to {id}, but slugs do not — so '
            .'the table grows with your content, and with any path a visitor invents. '
            .'That is the right setting for a site whose pages share one route; it is '
            .'worth checking retention.aggregates if the site is large.',
            'cairn.recorders.'.PageViews::class.'.group_by',
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
