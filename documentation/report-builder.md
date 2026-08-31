# The report builder

One query layer. The Blade dashboard, the Livewire and Inertia adapters, the
JSON API, the Pulse cards and CSV export all go through it, which is what keeps
their numbers identical by construction rather than by review.

If you find yourself writing a query against a Cairn table, it belongs in a
widget instead.

```php
use Divoto\Cairn\Facades\Cairn;
use Divoto\Cairn\Enums\{Comparison, Dimension, Metric, Period};

Cairn::report()
    ->lastDays(30)
    ->metrics(Metric::Visitors, Metric::Pageviews, Metric::BounceRate)
    ->groupBy(Dimension::Route)
    ->filter(Dimension::Route, 'pricing.index')
    ->compare(Comparison::PreviousPeriod)
    ->interval(Period::Day)
    ->orderByDesc(Metric::Pageviews)
    ->limit(20)
    ->get();
```

`Cairn::report()` returns a fresh builder each call, so two widgets on a page
cannot contaminate each other's filters.

## Output

| Method | Returns |
| --- | --- |
| `get()` | `Collection<ReportRow>` — one row per dimension value |
| `total()` | A single `ReportRow` of totals |
| `timeseries()` | One row per bucket, empty buckets included as zero |
| `realtime()` | Visitors active in the last five minutes |
| `toArray()` | Plain arrays |
| `toCsv()` | CSV with a header row |

`timeseries()` returns empty buckets rather than omitting them, so a quiet
Sunday shows as a trough rather than a gap the eye interpolates across.

## Additive and derived metrics

This is the part worth understanding.

**Additive** metrics — pageviews, sessions, bounces, events, conversions — are
stored in `cairn_aggregates` and can be summed across buckets and dimension
values.

**Derived** metrics are ratios of two additive ones and are **never stored**.
They are recomputed from their components at the level they are displayed at.

Why it matters:

| | Day one | Day two | Combined |
| --- | --- | --- | --- |
| Sessions | 10 | 90 | 100 |
| Bounces | 8 | 9 | 17 |
| Bounce rate | 80% | 10% | **17%** |

The average of the two daily rates is 45%. The correct figure is 17%. Nothing
about the display would tell you which one you were looking at, so Cairn makes
the distinction structural: `Metric::BounceRate->ratio()` returns its numerator
and denominator, and the builder decomposes before querying and recombines
after.

A ratio whose denominator is zero is **omitted rather than reported as zero**.
There is no bounce rate for zero sessions, and rendering `0%` would be a claim
the data does not support. `ReportRow::metric()` returns `null`; the dashboard
shows `—`.

## Unique visitors

`Metric::Visitors` does not come from the aggregate table — a set cardinality
cannot be summed out of a rollup after the fact. It comes from the
`UniqueCounter`, which counts distinct visitors per day as traffic arrives.

Over a range, daily counts are **summed**, so a person who visited on three
days counts three times. Any row spanning more than one day carries
`approximate = true` and the dashboard renders `~`.

Visitors are counted site-wide and per route. Asking for them at another
grouping throws rather than returning a wrong number.

## Narrowing to one dimension value

A filter without a grouping asks a question the rollup can answer — "how much
traffic came from Germany" — and the builder answers it by reading the country
rollup and collapsing it to a total:

```php
Cairn::report()
    ->lastDays(30)
    ->metrics(Metric::Pageviews)
    ->filter(Dimension::Country, 'DE')
    ->total();
```

`timeseries()` narrows the same way, which is what lets the dashboard chart one
country's traffic over time.

**Metrics that were never measured at that grain are omitted.** Sessions,
bounces and session duration come from `cairn_sessions`, which carries no
dimension columns, so they do not exist per country. `ReportRow::metric()`
returns `null` and the dashboard renders `—`. A zero would read as "Germany
sent no sessions", which is a claim about traffic rather than about what was
measured.

Unique visitors depend on which dimension you narrowed to. The counter is
written against keys chosen in advance — one site-wide and one per route — so
one route's visitors are a real number and one country's were never counted.

| | Site-wide | `filter(Route, …)` | `filter(Country, …)` |
| --- | --- | --- | --- |
| Pageviews | ✅ | ✅ | ✅ |
| Events, conversions, response time | ✅ | ✅ | ✅ |
| Unique visitors | ✅ | ✅ | `null` |
| Sessions, bounce rate, avg. session duration | ✅ | `null` | `null` |

Only one dimension at a time. Two filters describe an intersection that was
never rolled up, and throw for the same reason "top routes in Germany" does.

## Comparisons

```php
->compare(Comparison::PreviousPeriod)   // the window of equal length before
->compare(Comparison::PreviousYear)     // the same window a year earlier
```

`ReportRow::change(Metric)` returns the change as a ratio, or `null` when the
previous window was zero — a zero baseline has no meaningful percentage change,
and "+100%" would be invented.

## What it refuses

Cairn reads `cairn_aggregates` only, and throws
`UnavailableDimensionException` rather than falling back to scanning raw
entries. A silent fallback turns a fast dashboard into a table scan that nobody
notices until the table is large — and by then the raw rows it scanned are past
their retention window, so the numbers would be wrong as well as slow.

Three cases throw, each naming what you asked for:

**A dimension that is not rolled up.** `Url`, `Region`, `City`, `Language` and
`ScreenClass` are recorded but not materialised, because rolling them up writes
a row per distinct value per bucket, forever.

**A combination of two dimensions.** v1 materialises single-dimension rollups
only. "Top routes in Germany" needs the pair, which is not stored — whether it
is asked as `groupBy(Route)->filter(Country, 'DE')` or as two filters at once.

**Unique visitors at an uncounted grouping.** See above.

## Materialised dimensions

Route · Referrer host · Channel · UTM source/medium/campaign/term/content ·
Country · Device type · Browser · Operating system · Event name

Adding one means every rollup writes more rows for every bucket, forever, so
the list is deliberately short.

## Tenancy

Applied automatically from the configured `TenantResolver`, on write and on
read. It is never a caller's responsibility — isolation that depends on every
call site remembering is not isolation.

## Custom widgets

A widget declares what to ask and how it should look. It never renders and
never queries.

```php
use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Widgets\DimensionWidget;

final class Languages extends DimensionWidget
{
    public function key(): string   { return 'languages'; }
    public function title(): string { return 'Languages'; }

    protected function dimension(): Dimension
    {
        return Dimension::Language;
    }
}
```

Register it in `cairn.dashboard.widgets`. For anything that is not a ranked
table of one dimension, extend `Widget` directly and implement `query()` and
`schema()`.

Panels sit in a grid three-ish across. A dimension whose values are long can
override `protected function wide(): bool` to take the whole row instead —
`Top routes` does, because a route name in a third of a row is mostly ellipsis.
Put wide panels last in the widget list: one in the middle leaves a gap beside
the panel before it, since nothing narrow can be pulled up to fill the row.

A widget whose query fails is contained to its own panel — the rest of the
dashboard still renders.
