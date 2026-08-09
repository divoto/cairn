# Changelog

All notable changes to `divoto/cairn` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

From `1.0.0` onward the version number is a promise: the public API — the
`Cairn` facade, the `Divoto\Cairn\Contracts\*` interfaces, the published
config file, the `cairn:*` commands and the shape of the reporting payloads —
will not break within a major version. Anything under `Divoto\Cairn\Support`,
the storage schema and the Blade markup are internal and may change in a minor
release.

## [1.0.0] - 2026-08-09

### Changed

- **The version number is now a promise.** `1.0.0` carries no code changes over
  `0.4.0`; it is the commitment the `0.x` line deferred. The contracts a host
  application binds to, the facade its controllers call, the config keys it
  publishes and the commands its scheduler runs are fixed for the life of the
  major version, and the pre-release warnings in the README, the installation
  guide and the security policy have been retracted rather than left to
  contradict the tag. Everything the `0.x` releases shipped is unchanged —
  upgrading from `0.4.0` is a Composer constraint change and nothing else.

## [0.4.0] - 2026-08-08

### Changed

- **Countries are named, not coded.** The dashboard printed what the geo
  database returns — `PK`, `AE`, `AU` — which is a lookup exercise rather than
  a reading. `Dimension::Country->display()` now resolves the ISO 3166-1
  alpha-2 code through `Support\CountryNames`, an inlined CLDR table, and the
  filter chips read the same way so a chip cannot say `country PK` above a row
  that says `Pakistan`. The table is inlined rather than read from `ext-intl`:
  a label is not worth an extension requirement. An unknown code still shows
  itself, which is more use to a reader than a dash. Stored values, filter
  links and the API are untouched — this is presentation only.

- **Top routes takes the full width of the grid, and sits with the activity
  feed at the end of it.** A route name or a path is the longest value on the
  dashboard and was being ellipsised in a third of a row. Panels now declare
  their width through `WidgetSchema::$wide`, which `DimensionWidget` exposes as
  a `wide()` hook, and the renderers ask `isWide()` instead of testing for the
  feed layout. The two wide panels close the page because a wide panel placed
  mid-grid leaves a hole beside whatever narrow panel precedes it. The Inertia
  payload gained a `wide` flag so a custom front end can lay out the same way.

### Fixed

- **Livewire's asset tags leaked between tests.** "A component has been
  rendered" is a static flag, and Livewire injects its `<script>` into any 200
  HTML response once it is set; Testbench rebuilds the application between
  tests but not that static. A test rendering a Livewire component therefore
  put Livewire's script into the *next* test's plain server-rendered page,
  which the no-JavaScript assertion reads as a second script — a failure that
  appeared and vanished with the random ordering seed. The base test case now
  flushes Livewire state on teardown.

## [0.3.0] - 2026-08-07

### Fixed

- **Selecting the Livewire or Inertia dashboard did nothing.**
  `routes/dashboard.php` named the Blade controller outright, and the provider
  only ever branched on `none`, so `CAIRN_DASHBOARD=livewire` and
  `CAIRN_DASHBOARD=inertia` both served the Blade dashboard and answered 200 —
  a driver doing nothing at all was indistinguishable from one that worked. The
  adapters had been written but never routed: the Inertia controller was
  reachable from nothing but its own test. The route now asks
  `Integrations::dashboardController()`, which selects by driver and falls back
  to Blade when the optional package is not installed. Livewire gains a page
  controller, since the component carries no document of its own — it is also
  meant to be dropped into a host application's layout, and still can be.

  The adapters were covered from the day they were written, by a
  `Livewire::test()` on the component and a direct `props()` call on the
  Inertia controller. Neither ever requested the dashboard URL, which is how
  the gap between "the adapter works" and "selecting the adapter works" stayed
  invisible. The new tests assert the route.

- **The Today chart was hourly but labelled by date.** The series was already
  bucketed by hour; every label was rendered with `toFormattedDateString()`,
  so all 24 points — both axis ends and every row of the data table — read as
  today's date. `Format::bucket()` now labels a bucket at the granularity it
  was measured at: `09:00` for an hour, `Mar 14, 2026` for a day, `Mar 2026`
  for a month.

- **Today reported the whole day's visitors against every hour.** `Report`'s
  visitor count always iterated *day* buckets regardless of the charting
  interval, so a one-hour window returned the entire day's figure — a flat
  line that read as a real hourly measurement. There is no hourly number to
  report: uniqueness is counted per calendar day because the salt rotates
  every 24 hours, so a day is the smallest set there is anything to
  deduplicate within. The metric is now omitted from hourly rows rather than
  misreported, rendering as an em dash with the reason given under the table.
  Window totals are unchanged — a "today" total is still today's visitors,
  because that window *is* a day.

### Added

- **Hover readouts on the overview chart.** Each point carries an invisible
  full-height target and a native SVG `<title>` giving the bucket and its
  figures. No JavaScript: the values survive with scripting disabled, are
  announced by a screen reader, and the data table underneath remains the
  accessible path.

## [0.2.0] - 2026-08-05

### Added

- **`recorders.PageViews::class.group_by` chooses how a request becomes a row in
  the routes table.** Grouping by route name is what an external analytics tag
  cannot do, and it stays the default — but it assumes routes are mostly
  distinct pages. An application that serves its whole catalogue from one
  parameterised route (`/{page}`, `/docs/{slug}`) has a single route name for
  every page, so the entire site collapsed into one row and there was no way to
  change it: `RouteNameGrouper` is `final`, so it could not be swapped either.

  `'name'` (default) is the previous behaviour, `'uri'` groups by the route's URI
  pattern, and `'path'` groups by the requested path with numeric ids, UUIDs and
  ULIDs still collapsed to `{id}`. An unrecognised value falls back to `'name'`
  rather than throwing, because a config typo must not stop pageviews being
  recorded. The Top routes caption reads the setting rather than asserting a
  grouping the deployment may not be using, and `cairn:doctor` reports `'path'`,
  whose row count is bounded by what visitors request rather than by the route
  table.

## [0.1.2] - 2026-08-04

Fixes deployment on any application that installed Cairn without Pulse.

### Fixed

- **`php artisan view:cache` failed with `Unable to locate a class or view for
  component [pulse::card-header]`.** Cairn's two Pulse cards use Pulse's own
  `<x-pulse::card>` components, and they lived in `resources/views`, which is
  registered as the `cairn::` view namespace unconditionally. `view:cache`
  compiles every Blade file under every registered view path with no regard for
  configuration or class existence, so the cards were compiled in applications
  that had never installed Pulse and the command threw. Nothing was broken at
  runtime — the integration was correctly guarded and never reached — but
  `view:cache` is in Forge's default deploy script and in most Dockerfiles, so
  the deployment failed.

  The cards now live in `resources/pulse-views` under their own `cairn-pulse::`
  namespace, registered inside the same guard that registers the components:
  only when Pulse is installed *and* `cairn.pulse.enabled` is true. Setting that
  flag to `false` did not help before this release, because compilation never
  consulted it.

### Changed

- The Pulse cards publish under a new `cairn-pulse-views` tag rather than with
  `cairn-views`. Publishing them into an application without Pulse would put
  them back on a compiled path — the application's own — and break the same
  deploy from the other direction.

### Added

- `tests/Deployment/`, which runs `view:cache` and `config:cache` against an
  application holding Cairn and nothing else, and asserts that every view in the
  unconditionally registered namespace compiles with no optional package
  present. Four of its five tests fail without the fix above.

### Upgrading

Nothing to do. If you published the views with `--tag=cairn-views` before this
release, delete `resources/views/vendor/cairn/pulse/` — those two files are the
ones that break `view:cache`, and they are no longer part of that tag.

## [0.1.1] - 2026-08-04

Fixes PostgreSQL, on which no release before this one recorded anything.

### Fixed

- **PostgreSQL recorded nothing at all.** Cairn stores visitor, session and
  dimension identity as raw 16-byte hashes. Laravel binds every string
  parameter as `PDO::PARAM_STR`, and PostgreSQL validates text parameters
  against the database encoding — so a raw hash, which is almost never valid
  UTF-8, was rejected with `SQLSTATE[22021]` rather than stored. Because every
  driver on the request path swallows its own exceptions so that analytics can
  never break a response, the failure appeared only in the application log and
  all five tables stayed empty. MySQL, MariaDB and SQLite accept the identical
  parameter, which is why three engines hid it.

  Binary values are now emitted as `'\x…'::bytea` literals on PostgreSQL and
  bound as before on every other engine — see `Divoto\Cairn\Support\Binary`.
  This covers the entry writer, the session resolver, the database unique
  counter, the database presence driver, the beacon endpoint and the
  data-subject erase and export paths.

- **Rollups failed on PostgreSQL.** `sum(case when is_bounce = 1 …)` compared a
  real boolean against an integer, which PostgreSQL rejects as a type error
  rather than treating as false. Bounce and session-duration rollups now test
  the column for truth, which is portable across all four engines.

- **Subject-access exports were malformed on PostgreSQL.** `cairn:export` read
  binary columns without decoding them; PostgreSQL returns `bytea` as a stream
  resource, which `json_encode` cannot represent.

### Added

- `tests/Feature/BinaryColumnsTest.php`, which asserts that each write
  *landed* rather than that it did not throw — the distinction that let this
  ship. Nine of its ten tests fail against PostgreSQL without the fixes above.

### Note for existing PostgreSQL installations

No data migration is needed, and no schema changed: the tables were correct all
along and are simply empty. Historical traffic from before this release was
never written and cannot be recovered. `cairn:doctor` and the dashboard will
begin reporting as soon as the first request is recorded.

## [0.1.0] - 2026-08-03

First public release.

### Added

- **Recording.** Cookieless pageview, session, event and conversion tracking.
  A `TrackPageView` middleware records from `terminate()`, so nothing runs
  before the response. `Cairn::event()`, `Cairn::conversion()` and a
  `HasAnalytics` trait for Eloquent models.
- **Identity.** `VisitorHasher` derives an HMAC-SHA256 visitor hash from a
  32-byte salt that lives only in the cache and rotates every 24 hours. Salt
  rotation is clamped to at most 24 hours: configuration may tighten it, never
  loosen it.
- **The privacy gate.** Do Not Track, Global Privacy Control, a per-visitor
  opt-out and a consent resolver are all evaluated before bot detection,
  ignore rules and sampling, so no configuration can record against a
  visitor's expressed wish. Prefetches are declined.
- **Drivers.** Database and Redis families for ingest, unique counting and
  presence, covered by one shared test suite — 44 assertions run identically
  against both.
- **Schema.** Five tables, portable across MySQL, MariaDB, PostgreSQL and
  SQLite, with no column anywhere that can hold an IP address. Optional
  monthly partitioning via `cairn:partition`.
- **Rollups.** `cairn:rollup` rebuilds a window rather than incrementing it,
  making it both idempotent and a repair path. `cairn:prune` drops partitions
  where available. Both are scheduled automatically, and skipped if already
  scheduled by the application.
- **The report builder.** One query layer for every surface. Derived metrics
  are recomputed from their stored components at the level they are displayed
  at; combinations that were never materialised throw rather than silently
  scanning raw entries.
- **The dashboard.** Server-rendered Blade at `/cairn`, behind a `viewCairn`
  gate that denies everybody outside the local environment by default. Fully
  readable with JavaScript disabled, dark mode, every chart backed by a table.
  Fifteen widgets, extensible in one class.
- **Country reporting.** Optional and off by default. Lookups run against a
  local MaxMind database — never a third-party request per pageview — with the
  address masked before the resolver sees it and the result reduced to the
  configured precision before an entry is built. `cairn:geoip` downloads the
  database, verifies it against MaxMind's published checksum and installs it,
  replacing a working database only once a new one is known good. Any class
  implementing `GeoResolver` can be used instead.
- **Data-subject tooling.** `cairn:forget` erases a subject and rebuilds the
  aggregates their rows contributed to. `cairn:export` produces a subject
  access request as JSON. `cairn:doctor` reports what an installation stores
  and exposes, without asserting any legal conclusion. Publishable
  privacy-notice template and opt-out controller stub.
- Repository scaffold: Composer package definition, PSR-4 autoloading, package
  discovery, and the publish tags.
- Quality gates: Laravel Pint (`laravel` preset), PHPStan level 9 via Larastan
  with an empty baseline, Rector targeting PHP 8.2, and Pest with Orchestra
  Testbench.
- Architecture tests enforcing the project's architecture invariants: strict types
  throughout `src/`, no debugging helpers, no `env()` outside the config file,
  no facades outside `Divoto\Cairn\Facades`, and optional-package references
  confined to `Divoto\Cairn\Integrations`.
- Continuous integration across PHP 8.2–8.4 and Laravel 12–13, plus a database
  matrix covering SQLite, MySQL 8, MariaDB 11 and PostgreSQL 16.

[Unreleased]: https://github.com/divoto/cairn/commits/main

[1.0.0]: https://github.com/divoto/cairn/releases/tag/v1.0.0

[0.4.0]: https://github.com/divoto/cairn/releases/tag/v0.4.0

[0.3.0]: https://github.com/divoto/cairn/releases/tag/v0.3.0

[0.2.0]: https://github.com/divoto/cairn/releases/tag/v0.2.0

[0.1.2]: https://github.com/divoto/cairn/releases/tag/v0.1.2

[0.1.1]: https://github.com/divoto/cairn/releases/tag/v0.1.1

[0.1.0]: https://github.com/divoto/cairn/releases/tag/v0.1.0
