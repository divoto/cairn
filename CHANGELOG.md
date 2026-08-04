# Changelog

All notable changes to `divoto/cairn` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

While the version number is below `1.0.0`, minor releases may contain breaking
changes. The jump to `1.0.0` is a promise about stability and will not be made
until the package has run in production for a meaningful period.

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
- Architecture tests enforcing the invariants in `CLAUDE.md`: strict types
  throughout `src/`, no debugging helpers, no `env()` outside the config file,
  no facades outside `Divoto\Cairn\Facades`, and optional-package references
  confined to `Divoto\Cairn\Integrations`.
- Continuous integration across PHP 8.2–8.4 and Laravel 12–13, plus a database
  matrix covering SQLite, MySQL 8, MariaDB 11 and PostgreSQL 16.

[Unreleased]: https://github.com/divoto/cairn/commits/main

[0.1.1]: https://github.com/divoto/cairn/releases/tag/v0.1.1

[0.1.0]: https://github.com/divoto/cairn/releases/tag/v0.1.0
