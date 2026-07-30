# Changelog

All notable changes to `divoto/cairn` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

While the version number is below `1.0.0`, minor releases may contain breaking
changes. The jump to `1.0.0` is a promise about stability and will not be made
until the package has run in production for a meaningful period.

## [Unreleased]

### Added

- Repository scaffold: Composer package definition, PSR-4 autoloading, package
  discovery, and the `cairn-config` publish tag.
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