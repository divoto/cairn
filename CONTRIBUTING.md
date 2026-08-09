# Contributing to Cairn

Thanks for your interest. Cairn has a small number of rules that are not
negotiable, and knowing them up front will save you a rejected pull request.

## The privacy invariants

Cairn's value comes from what it refuses to do. A contribution that weakens any
of the following will be declined regardless of how well it is written:

1. **Raw IP addresses are never persisted.** An IP may exist in memory only long
   enough to derive the visitor hash and perform a geo lookup. It must never
   reach a database column, a log line, a cache entry, an exception message or a
   queue payload.
2. **No cookies in the default configuration.** Visitor identity is a salted
   hash. Cairn never writes `Set-Cookie` and never touches the Laravel session.
3. **The visitor salt rotates every 24 hours and is never written to disk.**
4. **Cross-day visitor identity is impossible by construction.** No fingerprint
   stitching, no fallback identifiers, no "probable same visitor" heuristics.
5. **Personal-data features are opt-in and loudly documented.**
6. **Never claim compliance with any regulation** in code, config, README or
   dashboard copy — and never give legal advice in documentation. Compliance is
   a property of a deployment, not of a library.
7. **Do Not Track and `Sec-GPC` are honoured by default.**

If a feature you want appears to require breaking one of these, please open an
issue to discuss it rather than sending a pull request that works around it.

## The technical invariants

- **Redis is optional, always.** Every Redis-backed capability needs a
  database-backed driver of equal correctness. `ext-redis` and `predis/predis`
  belong in `suggest`, never in `require`.
- **No build step.** A user must never need npm, Vite or Node to install and use
  Cairn.
- **Nothing blocks the response.** Every recording path is wrapped so failures
  are reported and swallowed, never rethrown.
- **The dashboard never scans raw entries.** Views reachable from the dashboard
  home read `cairn_aggregates` only.
- **Queries live in widget classes** — never in a view, a controller or a
  Livewire component.

## Getting set up

```bash
git clone https://github.com/divoto/cairn.git
cd cairn
composer install
```

**Redis is required to run the suite.** Not to use Cairn — Redis is optional
there and always will be — but the driver-parity tests exercise the Redis
drivers against a real server rather than skipping them, because a
conditionally-skipped driver is a driver nobody notices breaking. Any local
Redis will do; the suite uses database 15 so it will not touch your own keys.

```bash
REDIS_HOST=127.0.0.1 REDIS_PORT=6379 composer test
```

## Before you open a pull request

All three must be green:

```bash
composer test        # Pest
composer analyse     # PHPStan level 9, zero baseline entries
composer format      # Laravel Pint, laravel preset
```

`composer rector:check` should also pass.

If PHPStan cannot be satisfied, change the design. Do not add an ignore or a
baseline entry.

## Code standards

- `declare(strict_types=1);` at the top of every PHP file in `src/` and `tests/`.
- PHP 8.2 is the floor. No 8.3+ only syntax anywhere in `src/` — no typed class
  constants, no `json_validate()`, no `#[\Override]`.
- Classes are `final` unless designed for extension. If a class is extensible,
  say so in its docblock and cover it with a test.
- Full parameter, return and property types. No `mixed` without a docblock
  generic.

## Tests

- Pest, with `orchestra/testbench`. Target is 90%+ line coverage on `src/`;
  it currently sits at 94%.

  The gap is not laziness. What remains uncovered on SQLite is, almost
  entirely, code that cannot run there: `cairn:partition`'s `ALTER TABLE`, the
  `information_schema` lookups and `DROP PARTITION` in `Pruner`, and the
  `catch` blocks in the Redis drivers that need a deliberately broken
  connection. CI runs the same suite against MySQL, MariaDB and PostgreSQL,
  which is where those paths execute. Chasing them to 100% locally would mean
  mocking the database, and a mocked `DROP PARTITION` proves nothing about
  whether MySQL accepts it.
- Every driver pair (Redis vs database) is tested against the **same** suite via
  a shared dataset. Behaviour that differs between drivers is a bug.
- The privacy invariants get explicit regression tests, not incidental coverage.
- SQLite is the default target; MySQL, MariaDB and PostgreSQL run in CI.

## Commits

Conventional commit messages, please — `feat:`, `fix:`, `docs:`, `test:`,
`refactor:`, `chore:`.

## Security

Please do not report security issues through public issues or pull requests.
See [SECURITY.md](SECURITY.md).