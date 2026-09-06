# Cairn

**Privacy-first, self-hosted web analytics for Laravel.**

> Every visitor adds a stone. Nobody leaves a name.

Cairn is a single Composer package that gives a Laravel application cookieless
pageview, session and event tracking plus a built-in dashboard — without
sending anything to a third party, and without needing a cookie-consent banner
in its default configuration.

Because it runs inside your application rather than in a browser tag, it can
report on things an external tool cannot see: route names, Eloquent models, and
authenticated users.

```bash
composer require divoto/cairn
php artisan migrate
```

That is the whole install. Visit `/cairn`.

![The Cairn dashboard](https://raw.githubusercontent.com/divoto/cairn/main/art/dashboard-light.png)

---

## The honest trade-off

Cairn identifies visitors with a salted hash that is **regenerated from new
random bytes every 24 hours**. The old salt is destroyed, so yesterday's hashes
cannot be recomputed by anyone — including Cairn.

That single decision is where everything else follows from, in both directions.

| What you give up | What you get |
| --- | --- |
| **Returning visitors.** Somebody who visits on three days counts as three visitors. There is no way to know otherwise. | **No cookie banner in the default configuration.** Nothing is stored on the visitor's device. |
| **Multi-day journeys.** No "they read the blog on Monday and bought on Friday". | **No IP address in your database, logs or backups.** It exists in memory for one lookup, then it is gone. |
| **Multi-touch attribution.** Last-click only, because there is no earlier touch to attribute to. | **A breach of your analytics table leaks counts, not people.** |
| **Unique counts inflated over long ranges.** A month is the sum of its days. | **Route names, not URLs.** `/orders/8814/invoice` and `/orders/9921/invoice` are one page. |
| **Cross-device anything.** | **Eloquent models as first-class subjects.** `$article->trackView()`, then `$article->views()` and a Top content panel that names them. |
| **Exact time-on-page, scroll depth and Core Web Vitals** unless you enable the optional beacon. | **Nothing blocks the response.** Recording happens after the page is sent. |
| **A funnel.** No same-session path analysis. | **Bounce rate per landing page**, which the entry table alone cannot answer. |

If returning-visitor counts are essential to your work, Cairn is the wrong tool
and you should use something that sets a cookie and asks for consent. It will
do that job better.

## What it looks like

The dashboard is server-rendered Blade. It reads entirely without JavaScript —
filters are links, the chart is inline SVG computed on the server, and every
chart has a table underneath it. Dark mode follows your system.

![The dashboard in dark mode](https://raw.githubusercontent.com/divoto/cairn/main/art/dashboard-dark.png)

Eighteen widgets ship by default, and three more — Languages, Screen sizes and
Exit pages — are one line of config away. Each one is a class: reorder them,
remove them, or add your own by subclassing `Widget` — or `DimensionWidget`,
for a ranked table of a single dimension.

![Channels, countries, devices, browsers, operating systems and campaigns](https://raw.githubusercontent.com/divoto/cairn/main/art/widgets-dark.png)

The whole page, in one image:
[light](https://raw.githubusercontent.com/divoto/cairn/main/art/dashboard-full-light.png) ·
[dark](https://raw.githubusercontent.com/divoto/cairn/main/art/dashboard-full-dark.png).

A `~` on a visitor count is not decoration. It marks a number Cairn knows is an
overcount, for the reason in the table above — and the same marking is applied
to any figure that has been scaled back up from a sample.

## Requirements

- PHP 8.2+
- Laravel 12 or 13
- MySQL 8+, MariaDB 10.6+, or PostgreSQL 13+

**Redis is optional and always will be.** Every Redis-backed capability has a
database-backed driver of equal correctness, tested against the same suite.
Cairn runs on shared hosting with MySQL and nothing else.

**There is no build step.** No npm, no Vite, no Node. The dashboard's CSS is
~8KB, hand-written, and inlined.

## Driver matrix

| Capability | `database` (default) | `redis` |
| --- | --- | --- |
| Ingest | In-memory buffer, flushed after the response | List drained by `cairn:work` |
| Unique counting | Exact, one row per visitor per day | HyperLogLog, ~0.81% error |
| Presence | Table with a 5-minute window | Sorted set, self-expiring |
| Storage | The only storage driver in v1 | (uses the database driver) |

Both drivers are covered by one shared test suite — 44 assertions run
identically against each. A behavioural difference between them is a bug.

## Recording things yourself

```php
use Divoto\Cairn\Facades\Cairn;

Cairn::event('signed_up', ['plan' => 'pro']);
Cairn::conversion('purchase', 49.99);
Cairn::ignore('admin/*');           // for the rest of this request
```

On a model:

```php
use Divoto\Cairn\Concerns\HasAnalytics;

class Article extends Model
{
    use HasAnalytics;
}

$article->trackView();
$article->trackEvent('shared', ['network' => 'mastodon']);
```

## Reading the numbers

Every surface — the dashboard, the API, the Pulse cards, CSV export — goes
through one query layer, so they cannot disagree.

```php
use Divoto\Cairn\Enums\{Comparison, Dimension, Metric};

Cairn::report()
    ->lastDays(30)
    ->metrics(Metric::Visitors, Metric::Pageviews, Metric::BounceRate)
    ->groupBy(Dimension::Route)
    ->compare(Comparison::PreviousPeriod)
    ->orderByDesc(Metric::Pageviews)
    ->limit(20)
    ->get();
```

Derived metrics are recomputed from their stored components at the level they
are shown at. A week's bounce rate is that week's bounces over that week's
sessions — never the average of seven daily rates, which is a different and
wrong number.

Asking for something that was never rolled up throws, naming the combination.
Cairn will not silently fall back to scanning raw entries.

## Commands

| Command | What it does |
| --- | --- |
| `cairn:rollup` | Recompute aggregates for a window. Idempotent. |
| `cairn:prune` | Enforce retention. Drops partitions where available. |
| `cairn:work` | Drain the Redis ingest queue (redis driver only). |
| `cairn:partition` | Convert raw tables to monthly partitions (MySQL/MariaDB). |
| `cairn:doctor` | Report what this installation stores and exposes. |
| `cairn:geoip` | Download the MaxMind GeoLite2 database for country reporting. |
| `cairn:forget` | Erase a visitor or user, and rebuild affected rollups. |
| `cairn:export` | Export everything held about a subject, as JSON. |

Rollup and prune are scheduled automatically, and skipped if you have already
scheduled them yourself. On hosts with no cron at all, a small fraction of
requests carry the work instead.

Start with `cairn:doctor`. It reports what this particular installation stores
and exposes — observations, not errors, several of which may be entirely
deliberate.

![php artisan cairn:doctor](https://raw.githubusercontent.com/divoto/cairn/main/art/doctor.png)

## Compared with Matomo and GA4

Honest version: **Matomo and GA4 will tell you more about individual people
than Cairn can.** That is the difference, and it is deliberate.

| | Cairn | Matomo (self-hosted) | GA4 |
| --- | --- | --- | --- |
| Where data lives | Your database | Your server | Google |
| Cookies by default | None | Yes | Yes |
| IP stored | Never | Optional, on by default | Yes |
| Returning visitors | **Not possible** | Yes | Yes |
| Funnels, cohorts, session replay | **No** | Yes | Partly |
| Route names | Yes | No | No |
| Eloquent models | Yes | No | No |
| Install | `composer require` | Separate application | JS tag |
| Runtime cost | One buffered insert after the response | Separate app + database | Third-party request per page |

Cairn is not trying to replace Matomo's feature set. It is trying to answer
"which pages matter and where do people come from" without collecting anything
it would rather not hold.

## What Cairn deliberately does not do

Not "not yet" — these are decisions.

- **Follow anyone across days.** Not through fingerprint stitching, fallback
  identifiers, or "probably the same visitor" heuristics.
- **Store an IP address**, in any table, log, cache entry, exception message or
  queue payload.
- **Funnels, cohort analysis, heatmaps, session replay, A/B testing,
  attribution beyond last click.** Most of these need cross-day identity, which
  does not exist here.
- **Behavioural bot detection.** That means profiling visitors.
- **Request high-entropy client hints.** Cairn reads the low-entropy ones the
  browser volunteers and asks for nothing more.
- **Scan raw entries from the dashboard.** Ever.
- **Tell you whether you are compliant with anything.** See below.

## On compliance

Cairn is **privacy-first** and **cookieless by default**, and in its default
configuration it stores no personal data and needs no consent banner.

It does not and cannot tell you that your deployment complies with GDPR or any
other regulation. Compliance is a property of how software is deployed,
configured and operated — not of a library. Nothing in this package or its
documentation is legal advice.

Two settings change what Cairn stores, and both are off by default with the
consequences spelled out in `config/cairn.php`:

- `privacy.track_user_id` — attributes entries to the signed-in user, making
  the data personal data.
- `privacy.durable_identity` — replaces the rotating hash with a cookie,
  reversing the central design decision.

`cairn:doctor` reports on both, along with anything else worth knowing.

## Configuration

```bash
php artisan vendor:publish --tag=cairn-config
```

Every option is commented. The two above carry a longer explanation of what
they change.

Publish tags: `cairn-config`, `cairn-migrations`, `cairn-views`,
`cairn-assets`, `cairn-privacy`, `cairn-inertia`, `cairn-pulse-views`.

## Country reporting

Off by default. Cairn bundles no geo database and will not call a third-party
service per request — that would send a visitor's address off your server on
every pageview. Lookups happen against a local file instead:

```bash
composer require geoip2/geoip2
php artisan cairn:geoip          # needs free MaxMind credentials
```

Then set `privacy.geo_resolver` to `MaxMindGeoResolver::class`. Full walkthrough
in [documentation/geolocation.md](documentation/geolocation.md).

## Dashboard access

Guarded by a `viewCairn` gate that, exactly like Telescope and Pulse, **denies
everybody outside the local environment** until you define it:

```php
Gate::define('viewCairn', fn ($user) => $user?->isAdmin() ?? false);
```

## Dashboard drivers

The Blade dashboard is canonical: server-rendered, no build step, and the one
every number is checked against. Two wrappers exist if you would rather the
dashboard matched the stack you already run.

```env
CAIRN_DASHBOARD=blade      # default — needs no optional package
CAIRN_DASHBOARD=livewire   # needs livewire/livewire
CAIRN_DASHBOARD=inertia    # needs inertiajs/inertia-laravel
CAIRN_DASHBOARD=none       # register no dashboard route at all
```

Cairn requires neither package — both are `suggest` entries — so naming one that
is not installed serves the Blade dashboard rather than failing.

**Livewire** renders the same partials and asks the same widgets as the Blade
dashboard, so the two cannot report different numbers. What it adds is filtering
without a page load. Selecting the driver also registers the component as
`cairn-dashboard`, which is what lets you ignore Cairn's own route and put the
dashboard inside your application's layout:

```blade
<livewire:cairn-dashboard />
```

**No driver polls.** Every dashboard is a snapshot, as fresh as the request that
drew it and no fresher, and deliberately the same answer under all three — how
old a number is should not depend on which wrapper you chose. Live-updating the
widgets where staleness actually misleads is planned for a later release.

**Inertia** renders a `Cairn/Dashboard` page. Cairn ships the controller, its
typed props, and a TypeScript definition of them under `--tag=cairn-inertia` —
not a styled page component, which is yours to write against those types. A
dashboard that had to look right inside somebody else's design system is a
promise no package can keep. If you want one that works out of the box, use
Blade.

## Further reading

- [Installation](documentation/installation.md) · [The privacy model](documentation/privacy-model.md) · [The report builder](documentation/report-builder.md) · [Geolocation](documentation/geolocation.md)
- A longer write-up, with the full configuration reference and more screenshots:
  [ifhighlow.com/portfolio/cairn-privacy-first-analytics-for-laravel](https://ifhighlow.com/portfolio/cairn-privacy-first-analytics-for-laravel)
- The release announcement:
  [Introducing Cairn](https://ifhighlow.com/blog/introducing-cairn-privacy-first-analytics-for-laravel)

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). The privacy invariants are not
negotiable, and a contribution that weakens one will be declined however well
written it is.

Redis is required to run the test suite — not to use Cairn, but because the
driver-parity tests exercise both drivers against a real server rather than
skipping one.

## Status

**Stable.** Cairn follows semantic versioning from `1.0.0`. The `Cairn` facade,
the `Divoto\Cairn\Contracts\*` interfaces, the published config file, the
`cairn:*` commands and the shape of the reporting payloads will not break
within a major version. `Divoto\Cairn\Support`, the storage schema and the
Blade markup are internal and may change in a minor release.

## License

MIT. See [LICENSE.md](LICENSE.md).
