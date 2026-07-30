<?php

declare(strict_types=1);

use Divoto\Cairn\Consent\GrantingConsentResolver;
use Divoto\Cairn\Recorders\ClientMetrics;
use Divoto\Cairn\Recorders\Conversions;
use Divoto\Cairn\Recorders\PageViews;
use Divoto\Cairn\Tenancy\NullTenantResolver;

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | When false, Cairn binds its no-op drivers, registers no middleware and
    | records nothing. Reports still run and return empty results, so a
    | dashboard on a disabled installation shows empty states, not errors.
    |
    */

    'enabled' => env('CAIRN_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Salt scoping
    |--------------------------------------------------------------------------
    |
    | Mixed into the visitor hash so that the same person visiting two sites
    | that share a Cairn installation cannot be correlated across them. Leave
    | null to use the application URL's host.
    |
    */

    'domain' => env('CAIRN_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Storage location
    |--------------------------------------------------------------------------
    |
    | 'connection' selects the database connection Cairn reads and writes on.
    | Null uses the application default. Pointing Cairn at its own connection
    | keeps analytics writes off your primary database's replication stream.
    |
    | 'table_prefix' namespaces every Cairn table so the package can share a
    | schema with the host application. Changing it after data exists requires
    | renaming the tables yourself.
    |
    */

    'connection' => env('CAIRN_DB_CONNECTION'),

    'table_prefix' => 'cairn_',

    /*
    |--------------------------------------------------------------------------
    | Salt storage
    |--------------------------------------------------------------------------
    |
    | The visitor salt lives in the cache and nowhere else. Null uses the
    | application's default store.
    |
    | Choose a memory-backed store if you have one. The salt's unlinkability
    | guarantee — that a rotated salt cannot be recovered, so yesterday's
    | hashes can never be recomputed — is only as strong as the store's
    | forgetfulness. A file or database store writes it to disk, where a backup
    | may outlive the rotation. cairn:doctor reports that condition.
    |
    */

    'cache_store' => env('CAIRN_CACHE_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | Selects the backing store for ingest buffering, unique counting and
    | presence. Both drivers are equally correct — 'redis' trades a dependency
    | for throughput, and nothing else.
    |
    | Cairn is fully functional on 'database' with MySQL and nothing else, and
    | that will never change. Redis is a suggestion, never a requirement.
    |
    | Supported: "database", "redis"
    |
    */

    'driver' => env('CAIRN_DRIVER', 'database'),

    'redis' => [
        // The connection name from config/database.php. Use a connection that
        // is NOT shared with your queue: a queue flush would take Cairn's
        // buffered entries with it.
        'connection' => env('CAIRN_REDIS_CONNECTION', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ingest
    |--------------------------------------------------------------------------
    |
    | Nothing here blocks a response. Entries are buffered during the request
    | and flushed from a terminating callback.
    |
    */

    'ingest' => [
        // How many entries to hold in memory before flushing early. A request
        // that produces more than this sheds the excess rather than growing
        // without bound.
        'buffer' => env('CAIRN_INGEST_BUFFER', 500),

        // Chance per request of triggering rollup and prune inline, as
        // [chances, out_of]. This exists so Cairn works on a host with no
        // scheduler at all. Running the scheduler is the recommended setup;
        // this is the fallback, not the plan.
        'lottery' => [2, 100],
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy
    |--------------------------------------------------------------------------
    |
    | The defaults here are the reason Cairn exists. Read the two flagged
    | options below before changing them.
    |
    */

    'privacy' => [
        // Honour the Do Not Track header. When a visitor sends DNT: 1, nothing
        // about their request is recorded.
        'respect_dnt' => true,

        // Honour Global Privacy Control (Sec-GPC: 1) the same way.
        'respect_gpc' => true,

        /*
        |----------------------------------------------------------------------
        | ⚠  PERSONAL DATA — off by default. Read before enabling.
        |----------------------------------------------------------------------
        |
        | Attributes entries to the authenticated user's ID, writing it to
        | cairn_entries.user_id.
        |
        | This changes what Cairn stores in kind, not just in degree. In its
        | default configuration Cairn holds no personal data: a visitor hash is
        | unlinkable to a person and is regenerated from a new salt every 24
        | hours. Enabling this stores an identifier that points directly at a
        | row in your users table, which makes the analytics data personal data
        | and brings it inside whatever obligations already apply to your user
        | records — retention, access requests, erasure, breach notification.
        |
        | It also makes the "no consent banner required in the default
        | configuration" statement inapplicable to your deployment.
        |
        | Cairn gives you cairn:forget and cairn:export to service data-subject
        | requests, and cairn:doctor will report that this is on. Whether
        | enabling it is appropriate is a decision for you and your legal
        | advisers; this file is not legal advice.
        |
        */
        'track_user_id' => env('CAIRN_TRACK_USER_ID', false),

        /*
        |----------------------------------------------------------------------
        | ⚠  COOKIE MODE — off by default. Read before enabling.
        |----------------------------------------------------------------------
        |
        | Replaces the daily-rotating hash with a durable first-party cookie,
        | so a returning visitor is recognised across days.
        |
        | This is the one setting that reverses Cairn's central design
        | decision. Cookieless identity is what lets the default configuration
        | avoid a consent banner; a durable identifier stored on a visitor's
        | device is exactly the thing consent regimes are written about, and
        | enabling it will typically require you to ask for consent before
        | Cairn records anything.
        |
        | What you gain: accurate returning-visitor and multi-day unique
        | counts. What you lose: the ability to say you set no cookies, and the
        | guarantee that cross-day identity is impossible by construction.
        |
        | cairn:doctor will report that this is on. This file is not legal
        | advice.
        |
        */
        'durable_identity' => env('CAIRN_DURABLE_IDENTITY', false),

        // How often the visitor salt is regenerated. In the default privacy
        // mode this is fixed at 24 hours and is not extendable: a longer
        // window would make visitors linkable across a longer period, which
        // is the property this package is built to remove.
        'salt_rotation_hours' => 24,

        // How precisely a resolved location may be stored: "none", "country",
        // "region" or "city". Anything finer than this is discarded before an
        // entry is built — it never reaches a column.
        //
        // Each step beyond "country" narrows the anonymity set of a visitor
        // hash. A country plus a browser plus a device class describes a very
        // large group of people; a city plus the same attributes may describe
        // a handful. cairn:doctor reports anything above "country".
        'geo_precision' => 'country',

        // A class implementing Divoto\Cairn\Contracts\ConsentResolver, for
        // deployments with obligations Cairn cannot know about. The default
        // grants, because the default configuration stores no personal data.
        //
        // Note this does not bypass the rest of the gate: DNT, GPC and the
        // per-visitor opt-out are still honoured independently.
        'consent_resolver' => GrantingConsentResolver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Raw data is short-lived; rollups are the permanent record. Values are in
    | days. Null means keep forever.
    |
    | Aggregates default to null because they contain no visitor-level data —
    | they are counts, not records of people. Raw entries and sessions default
    | to 30 days, which is enough to drill into a recent anomaly and short
    | enough that the raw table stays small.
    |
    */

    'retention' => [
        'entries' => 30,
        'sessions' => 30,
        'aggregates' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Recorders
    |--------------------------------------------------------------------------
    |
    | What Cairn records, and how much of it. Add your own by subclassing
    | Divoto\Cairn\Recorders\Recorder and registering it here.
    |
    | 'sample_rate' is a fraction between 0.0 and 1.0. Sampled numbers are
    | scaled back up for display and marked approximate with a "~" prefix, so a
    | sampled dashboard never silently under-reports.
    |
    */

    'recorders' => [
        PageViews::class => [
            'enabled' => true,
            'sample_rate' => 1.0,

            // Paths, route names or closures to never record. Wildcards are
            // matched with Str::is(), so "admin/*" works.
            'ignore' => [
                'horizon*',
                'nova*',
                'pulse*',
                'telescope*',
                'cairn*',
                'livewire*',
                '_debugbar*',
                '_ignition*',
                'up',
            ],
        ],

        ClientMetrics::class => [
            'enabled' => true,
        ],

        Conversions::class => [
            'enabled' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | Access is controlled by the "viewCairn" gate, which — exactly as
    | Telescope and Pulse do — permits nobody outside the local environment
    | until you define it. Publish the service provider stub to open it up.
    |
    | Supported drivers: "blade", "livewire", "inertia", "none". The Blade
    | dashboard is canonical and needs no build step; the others are wrappers
    | that register only when their package is installed.
    |
    */

    'dashboard' => [
        'enabled' => true,
        'driver' => env('CAIRN_DASHBOARD', 'blade'),
        'path' => env('CAIRN_PATH', 'cairn'),
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | JSON API
    |--------------------------------------------------------------------------
    |
    | Disabled by default. It is backed by the same report builder as the
    | dashboard, so it can read everything the dashboard can — which is why it
    | is off until you decide who may call it.
    |
    */

    'api' => [
        'enabled' => false,
        'middleware' => ['api', 'auth:sanctum'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    |
    | When enabled, the resolver's tenant is applied automatically on write and
    | on read. It is never a caller's responsibility to scope a query —
    | isolation that depends on every call site remembering is not isolation.
    |
    */

    'tenancy' => [
        'enabled' => false,
        'resolver' => NullTenantResolver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pulse cards
    |--------------------------------------------------------------------------
    |
    | Registers a Live Visitors and a Top Routes card when laravel/pulse is
    | installed. The cards read Cairn's own storage — no Cairn data is written
    | to any pulse_* table.
    |
    */

    'pulse' => [
        'enabled' => true,
    ],

];
