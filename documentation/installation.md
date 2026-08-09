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

## Optional: how pages are grouped

By default the routes table is grouped by **route name**, which is why
`/orders/8814/invoice` and `/orders/9921/invoice` count as one page rather than
two. That is the right answer for an application whose routes are mostly
distinct pages.

It is the wrong answer if most of your pages come from one parameterised route —
a CMS on `/{page}`, docs on `/docs/{slug}`. There the route name is the same
string for every page, and the whole site arrives on a single row. Group by path
instead:

```php
// config/cairn.php
'recorders' => [
    PageViews::class => [
        'group_by' => 'path',
    ],
],
```

| Setting  | `/blog/hello-world` | `/orders/8814/invoice`   |
| -------- | ------------------- | ------------------------ |
| `'name'` | `pages.show`        | `orders.invoice`         |
| `'uri'`  | `/{page}`           | `/orders/{order}/invoice` |
| `'path'` | `/blog/hello-world` | `/orders/{id}/invoice`   |

Under `'path'`, numeric ids, UUIDs and ULIDs still collapse to `{id}` — a slug is
a name, an order id is not. The cost is that the number of distinct rows is
bounded by what visitors request rather than by your route table, so rollups grow
with your content. `cairn:doctor` mentions it.

The setting applies from the moment you change it. Entries already recorded keep
the grouping they were recorded with, so a switch shows up as old rows staying
put and new ones appearing beside them.

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

## Optional: Livewire or Inertia

The Blade dashboard needs no build step and is the one every number is checked
against. If you would rather the dashboard matched the stack you already run:

```env
CAIRN_DASHBOARD=livewire   # needs livewire/livewire
CAIRN_DASHBOARD=inertia    # needs inertiajs/inertia-laravel
```

Cairn requires neither package — both are `suggest` entries — so naming one that
is not installed serves the Blade dashboard rather than failing. `none` registers
no dashboard route at all, leaving recording, the commands and the JSON API
running.

The Livewire driver renders the same Blade partials and asks the same widgets as
the server-rendered dashboard, so the two cannot drift into reporting different
numbers. Selecting it also registers the component as `cairn-dashboard`, which is
what lets you skip Cairn's own route and embed the dashboard in a page of your
own:

```blade
<livewire:cairn-dashboard />
```

Embedded, the component brings its own copy of the stylesheet, because your
layout supplies the document and Cairn's does not. Served at Cairn's path it
does not, because the packaged layout has already inlined it.

What the Livewire driver adds is filtering without a page load. It does not
poll: every dashboard is a snapshot, as fresh as the request that drew it and no
fresher, and that is deliberately the same answer under all three drivers — how
old a number is should not depend on which wrapper you chose. Live-updating the
two widgets where staleness actually misleads, live visitors and the activity
feed, is planned for a later release.

The Inertia driver renders a `Cairn/Dashboard` page. Cairn ships the controller
and its typed props; the page component is yours to write:

```bash
php artisan vendor:publish --tag=cairn-inertia   # resources/js/cairn
```

That publishes a TypeScript definition of every prop the controller renders — a
test keeps the two in step — and nothing else. Cairn does not ship styled
components, because a dashboard that had to look right inside somebody else's
design system is a promise no package can keep.

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

Cairn follows semantic versioning from `1.0.0`, so a minor release will not
break the public API — but the storage schema is internal and migrations do
ship in minor releases. Read [CHANGELOG.md](../CHANGELOG.md), run
`php artisan migrate`, then `php artisan cairn:doctor`.
