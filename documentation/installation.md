# Installation

```bash
composer require divoto/cairn
php artisan migrate
```

Visit `/cairn`. In a local environment it is already accessible; anywhere else
you need to define the gate first (below).

That is genuinely the whole install. There is no build step, no npm, and no
configuration required to start recording.

![The Cairn dashboard](https://raw.githubusercontent.com/divoto/cairn/main/art/dashboard-light.png)

## What just happened

- The service provider was discovered automatically.
- Five tables were created: `cairn_entries`, `cairn_sessions`,
  `cairn_aggregates`, `cairn_visitor_days`, `cairn_presence`.
- A `TrackPageView` middleware was appended to the `web` group. It does nothing
  during the request and records from `terminate()`, after the response has
  been sent.
- `cairn:rollup` and `cairn:prune` were registered with the scheduler — unless
  you had already scheduled them yourself.

## Check what you have

```bash
php artisan cairn:doctor
```

This reports what your installation actually stores and exposes, including
anything worth reconsidering. Run it before you go live.

## Open the dashboard in production

The `viewCairn` gate denies everybody outside the local environment until you
define it — the same stance Telescope and Pulse take. An analytics dashboard
reachable by anyone who guesses the URL is a data leak.

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('viewCairn', function ($user) {
        return $user?->isAdmin() ?? false;
    });
}
```

## Recommended: a memory-backed cache

Cairn's central guarantee — that yesterday's visitor hashes cannot be
recomputed — depends on the salt being genuinely gone after rotation. A `file`
or `database` cache store writes it to disk.

```env
CAIRN_CACHE_STORE=redis
```

## Recommended: run the scheduler

If Laravel's scheduler is running, Cairn's maintenance is already registered
and you can take the work off your requests entirely:

```php
// config/cairn.php
'ingest' => [
    'lottery' => [0, 100],
],
```

Without a scheduler, leave the lottery alone: a small fraction of requests will
carry the rollup and prune work after their response has been sent.

## Optional: Redis

```env
CAIRN_DRIVER=redis
CAIRN_REDIS_CONNECTION=cairn
```

Then run a worker: `php artisan cairn:work`.

Use a Redis connection that is **not** shared with your queue — flushing the
queue would take Cairn's buffered entries with it. `cairn:doctor` warns if you
have.

## Optional: partitioning

On MySQL or MariaDB, with tables large enough for pruning to hurt:

```bash
php artisan cairn:partition --months=13
```

`cairn:prune` then drops whole months as a metadata operation instead of
deleting rows. On PostgreSQL and SQLite the command explains that it cannot
help and exits successfully.

## Publishing

```bash
php artisan vendor:publish --tag=cairn-config      # config/cairn.php
php artisan vendor:publish --tag=cairn-migrations  # to customise the schema
php artisan vendor:publish --tag=cairn-views       # to restyle the dashboard
php artisan vendor:publish --tag=cairn-assets      # public/vendor/cairn
php artisan vendor:publish --tag=cairn-privacy     # privacy notice + opt-out stub
```

If you publish the migrations, call `CairnServiceProvider::ignoreMigrations()`
from a service provider, or the same tables will be created twice.

The Pulse cards have a tag of their own, `cairn-pulse-views`, and are
deliberately not part of `cairn-views`. They are the only views Cairn ships
that cannot be compiled without an optional package installed — they use
Pulse's `<x-pulse::card>` components — so publishing them into an application
without Pulse would put them on a path `view:cache` walks, and break the
deployment. Publish them only if you have Pulse and want to restyle the cards.

**A note on publishing config.** Laravel merges only top-level configuration
keys, so a published `config/cairn.php` will not pick up new nested options
added in later versions. Re-read the packaged file after upgrading, or leave
config unpublished and set what you need through environment variables.

## Upgrading

Cairn is pre-1.0 and minor releases may contain breaking changes. Read
[CHANGELOG.md](../CHANGELOG.md), run `php artisan migrate`, then
`php artisan cairn:doctor`.
