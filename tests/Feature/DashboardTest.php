<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Divoto\Cairn\Contracts\Presence;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Contracts\UniqueCounter;
use Divoto\Cairn\Data\Entry;
use Divoto\Cairn\Data\ReportRow;
use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\EntryType;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Enums\Period;
use Divoto\Cairn\Facades\Cairn;
use Divoto\Cairn\Reporting\Report;
use Divoto\Cairn\Support\Tables;
use Divoto\Cairn\Widgets\Filters;
use Divoto\Cairn\Widgets\Shipped\Overview;
use Divoto\Cairn\Widgets\Shipped\TopRoutes;
use Divoto\Cairn\Widgets\Widget;
use Divoto\Cairn\Widgets\WidgetLayout;
use Divoto\Cairn\Widgets\WidgetRegistry;
use Divoto\Cairn\Widgets\WidgetSchema;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

/**
 * A widget whose query fails, to prove a broken panel cannot take the page
 * down with it.
 */
final class BrokenWidget extends Widget
{
    public function key(): string
    {
        return 'broken';
    }

    public function title(): string
    {
        return 'Broken';
    }

    public function query(Filters $filters): Report
    {
        // Url is recordable but never rolled up, so this throws.
        return $this->report($filters)->groupBy(Dimension::Url);
    }

    public function schema(): WidgetSchema
    {
        return new WidgetSchema(
            layout: WidgetLayout::Table,
            dimension: 'url',
        );
    }
}

beforeEach(function (): void {
    // The gate defaults to local-only, which is what the suite runs as.
    Gate::define('viewCairn', fn (mixed $user = null): bool => true);
});

function seedTraffic(): void
{
    $today = CarbonImmutable::now('UTC')->startOfDay()->addHours(9);

    foreach ([
        ['pricing.index', 'GB'],
        ['pricing.index', 'GB'],
        ['pricing.index', 'DE'],
        ['home.index', 'GB'],
        ['docs.index', 'FR'],
    ] as $index => [$route, $country]) {
        /** @var Collection<int, Entry> $collection */
        $collection = new Collection([new Entry(
            occurredAt: $today->addMinutes($index),
            type: EntryType::Pageview,
            visitor: random_bytes(16),
            route: $route,
            url: '/'.$route,
            country: $country,
            durationMs: 20,
        )]);

        app(Storage::class)->store($collection);
    }

    app(UniqueCounter::class)->add($today->format('Y-m-d'), 'overall', random_bytes(16));
    app(UniqueCounter::class)->add($today->format('Y-m-d'), 'overall', random_bytes(16));

    app(Storage::class)->rollup($today->startOfDay(), $today->endOfDay(), Period::Day);
    app(Storage::class)->rollup($today->startOfDay(), $today->endOfDay(), Period::Hour);
}

/*
|--------------------------------------------------------------------------
| The gate
|--------------------------------------------------------------------------
|
| An analytics dashboard reachable by anyone who guesses the URL is a data
| leak, so the shipped default denies everybody outside the local environment
| — the same stance Telescope and Pulse take.
|
*/

it('denies access when the gate refuses', function (): void {
    Gate::define('viewCairn', fn (mixed $user = null): bool => false);

    cairnTest()->get('/cairn')->assertForbidden();
});

it('allows access when the gate permits', function (): void {
    cairnTest()->get('/cairn')->assertOk();
});

it('returns 403 rather than redirecting to a login page', function (): void {
    Gate::define('viewCairn', fn (mixed $user = null): bool => false);

    // A redirect would confirm the dashboard exists at that URL.
    cairnTest()->get('/cairn')->assertStatus(403)->assertHeaderMissing('Location');
});

/*
|--------------------------------------------------------------------------
| Rendering
|--------------------------------------------------------------------------
*/

it('renders the dashboard with data', function (): void {
    seedTraffic();

    cairnTest()->get('/cairn')
        ->assertOk()
        ->assertSee('Cairn')
        ->assertSee('Overview')
        ->assertSee('Top routes')
        ->assertSee('pricing.index');
});

/**
 * A route name is the longest value on the dashboard, so its panel takes the
 * whole row — and a wide panel in the middle of the grid leaves a gap beside
 * the narrow panel before it, so it sits with the feed at the end.
 */
it('draws top routes as a full-width panel above the activity feed', function (): void {
    seedTraffic();

    $html = asString(cairnTest()->get('/cairn')->assertOk()->getContent());

    preg_match('/<section class="([^"]*)"\s+aria-labelledby="w-top-routes"/', $html, $panel);

    expect($panel[1] ?? '')->toContain('panel-wide')
        ->and(strpos($html, 'w-top-routes'))->toBeLessThan((int) strpos($html, 'w-activity-feed'));
});

/**
 * "PK" is what a geo database returns; it is not what anybody wants to read.
 */
it('names a country rather than showing its code', function (): void {
    seedTraffic();

    cairnTest()->get('/cairn')
        ->assertOk()
        ->assertSee('United Kingdom')
        ->assertSee('Germany')
        ->assertDontSee('>GB<', false);
});

it('names the country in an active filter chip too', function (): void {
    seedTraffic();

    cairnTest()->get('/cairn?country=GB')
        ->assertOk()
        ->assertSee('Remove the country filter')
        ->assertSee('United Kingdom');
});

it('renders on an installation with no data at all', function (): void {
    cairnTest()->get('/cairn')
        ->assertOk()
        ->assertSee('Nothing recorded in this period');
});

it('tells search engines not to index it', function (): void {
    cairnTest()->get('/cairn')->assertOk()->assertSee('noindex, nofollow', false);
});

/**
 * The acceptance criterion: the dashboard is fully readable with scripting
 * disabled. Filters are links, the chart is inline SVG, and the only script on
 * the page remembers a theme preference.
 */
it('needs no JavaScript to be read', function (): void {
    seedTraffic();

    $html = asString(cairnTest()->get('/cairn')->assertOk()->getContent());

    // Every filter is a link, not a handler.
    expect($html)->toContain('href')
        ->and($html)->toContain('<svg')
        ->and($html)->not->toContain('onclick=')
        ->and($html)->not->toContain('onchange=');

    // One script block, and the dashboard renders identically without it.
    expect(substr_count($html, '<script'))->toBe(1);
});

/**
 * A line drawn as an image is unreadable to a screen reader and unusable to
 * anyone who wants the figures.
 */
it('gives every chart a table equivalent', function (): void {
    seedTraffic();

    cairnTest()->get('/cairn')
        ->assertOk()
        ->assertSee('Show these figures as a table')
        ->assertSee('Pageviews and visitors per');
});

it('labels the chart for a screen reader', function (): void {
    seedTraffic();

    cairnTest()->get('/cairn')->assertOk()->assertSee('role="img"', false);
});

it('ships both light and dark themes', function (): void {
    $html = asString(cairnTest()->get('/cairn')->assertOk()->getContent());

    expect($html)->toContain('prefers-color-scheme: dark')
        ->and($html)->toContain('[data-theme="dark"]')
        ->and($html)->toContain('[data-theme="light"]');
});

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

it('keeps every filter in the query string', function (): void {
    seedTraffic();

    cairnTest()->get('/cairn?range=7d')
        ->assertOk()
        ->assertSee('range=7d', false);
});

it('marks the selected range', function (): void {
    cairnTest()->get('/cairn?range=7d')->assertOk()->assertSee('aria-current="true"', false);
});

it('falls back to the default range for an unknown one', function (): void {
    $filters = Filters::fromRequest(Request::create('/cairn?range=nonsense'));

    expect($filters->range)->toBe('30d');
});

it('shows a removable chip for an active filter', function (): void {
    seedTraffic();

    cairnTest()->get('/cairn?route=pricing.index')
        ->assertOk()
        ->assertSee('Remove the route filter');
});

/*
|--------------------------------------------------------------------------
| Narrowing to one dimension
|--------------------------------------------------------------------------
|
| Clicking a country used to change nothing but the country panel itself: the
| chip appeared, the URL carried the value, and every headline number stayed
| site-wide. The rollup does hold pageviews per country, so the totals and the
| chart can honour the filter; the session metrics and the visitor count were
| never measured at that grain and are withheld rather than guessed at.
|
*/

it('narrows the headline totals and the chart to the selected country', function (): void {
    seedTraffic();

    $overview = app(Overview::class);
    $filters = new Filters(range: 'today', country: 'GB');

    // Three of the five seeded pageviews came from GB.
    expect($overview->rows($filters)->first()?->metric(Metric::Pageviews))->toBe(3.0)
        ->and($overview->series($filters)->sum(
            fn (ReportRow $row): float => $row->metric(Metric::Pageviews) ?? 0.0
        ))->toBe(3.0);
});

/**
 * Sessions live in `cairn_sessions`, which has no dimension columns, so there
 * is no such number as "sessions from Germany". Reporting the site's figure
 * under the country's heading, or a zero that reads as "none", would both be
 * claims the data does not support.
 */
it('withholds the metrics that were never measured per country', function (): void {
    seedTraffic();

    $row = app(Overview::class)->rows(new Filters(range: 'today', country: 'GB'))->first();

    expect($row?->metric(Metric::Pageviews))->toBe(3.0)
        ->and($row?->metric(Metric::Sessions))->toBeNull()
        ->and($row?->metric(Metric::Visitors))->toBeNull()
        ->and($row?->metric(Metric::BounceRate))->toBeNull();
});

it('renders a withheld metric as an em dash rather than a zero', function (): void {
    seedTraffic();

    $html = asString(cairnTest()->get('/cairn?range=today&country=GB')->assertOk()->getContent());

    // The pageview figure is the country's, and the stats it cannot answer
    // are blank rather than reading as a measured nothing.
    expect($html)->toContain('—')
        ->and($html)->toContain('Remove the country filter');
});

/**
 * The panels the filter cannot reach are the ones grouped by another
 * dimension. They keep showing site-wide numbers — there is no other number
 * to show — so they say as much rather than letting the chip imply otherwise.
 */
it('marks the panels a filter cannot narrow as site-wide', function (): void {
    seedTraffic();

    $html = asString(cairnTest()->get('/cairn?country=GB')->assertOk()->getContent());

    expect($html)->toContain('not narrowed by the country filter');

    // The countries panel is the one panel the filter does reach.
    preg_match('/w-countries.*?<\/div>/s', $html, $panel);
    expect($panel[0] ?? '')->not->toContain('not narrowed by');
});

/**
 * Routes and channels are clickable for the same reason countries are, and go
 * down the same path. A channel value is an int-backed enum, so the link
 * carries its stored value rather than its label — worth a test, because a
 * mismatch between what the link writes and what the rollup key holds is
 * invisible until the numbers come back empty.
 */
it('narrows the headline totals for every clickable dimension', function (Filters $filters, float $expected): void {
    seedTraffic();

    expect(app(Overview::class)->rows($filters)->first()?->metric(Metric::Pageviews))
        ->toBe($expected);
})->with([
    // Of the five seeded pageviews: three from GB, three on pricing.index.
    'country' => [fn (): Filters => new Filters(range: 'today', country: 'GB'), 3.0],
    'route' => [fn (): Filters => new Filters(range: 'today', route: 'pricing.index'), 3.0],
]);

/**
 * Visitors are counted per route as traffic arrives but never per country, so
 * the same click on two different panels honestly gives different answers.
 */
it('shows a visitor count for a route filter but not for a country', function (): void {
    seedTraffic();

    $overview = app(Overview::class);

    expect($overview->rows(new Filters(range: 'today', route: 'pricing.index'))
        ->first()?->metric(Metric::Visitors))->not->toBeNull()
        ->and($overview->rows(new Filters(range: 'today', country: 'GB'))
            ->first()?->metric(Metric::Visitors))->toBeNull();
});

it('replaces the active filter rather than combining two', function (): void {
    $filters = (new Filters(range: '7d', country: 'GB'))->with('route', 'pricing.index');

    expect($filters->route)->toBe('pricing.index')
        ->and($filters->country)->toBeNull()
        ->and($filters->active())->toBe(['route' => 'pricing.index'])
        ->and($filters->range)->toBe('7d');
});

/**
 * A hand-written URL naming two dimensions asks for an intersection that was
 * never rolled up. One is honoured, and the link the page writes back carries
 * only that one rather than propagating the ignored half.
 */
it('honours one dimension when a URL names two', function (): void {
    $filters = Filters::fromRequest(Request::create('/cairn?route=pricing.index&country=GB'));

    expect($filters->active())->toBe(['route' => 'pricing.index'])
        ->and($filters->dimension())->toBe('route')
        ->and($filters->toQuery())->not->toHaveKey('country');
});

it('charts a single day by hour and a year by month', function (): void {
    expect((new Filters(range: 'today'))->interval())->toBe(Period::Hour)
        ->and((new Filters(range: '30d'))->interval())->toBe(Period::Day)
        ->and((new Filters(range: '12m'))->interval())->toBe(Period::Month);
});

/**
 * Formatting an hourly bucket as a date prints today's date 24 times, which
 * tells the reader nothing about which hour is which.
 */
it('labels an hourly chart by hour rather than by date', function (): void {
    seedTraffic();

    $html = asString(cairnTest()->get('/cairn?range=today')->assertOk()->getContent());

    // Seeded traffic all lands in the 09:00 bucket.
    expect($html)->toContain('09:00')
        ->and($html)->toContain('00:00')
        ->and($html)->not->toContain(CarbonImmutable::now('UTC')->toFormattedDateString());
});

/**
 * Visitors are counted per day, so an hourly bucket has none. Showing the
 * day's total against every hour would read as a real hourly measurement.
 */
it('shows no per-hour visitor count but keeps the day total', function (): void {
    seedTraffic();

    $html = asString(cairnTest()->get('/cairn?range=today')->assertOk()->getContent());

    // The table says why the column is empty rather than leaving the reader to
    // interpret a row of em dashes. The headline figure is unaffected — that
    // covers the whole window, which today *is* a day; see ReportTest.
    expect($html)->toContain('visitors are counted per day');
});

it('gives every chart point a hover readout that needs no JavaScript', function (): void {
    seedTraffic();

    $html = asString(cairnTest()->get('/cairn?range=today')->assertOk()->getContent());

    expect($html)->toContain('<title>09:00 · 5 pageviews</title>')
        ->and($html)->toContain('class="chart-hit"');
});

it('caps a filter value read from the URL', function (): void {
    $filters = Filters::fromRequest(
        Request::create('/cairn?route='.str_repeat('a', 5000))
    );

    expect(strlen($filters->route ?? ''))->toBe(255);
});

/*
|--------------------------------------------------------------------------
| Widgets
|--------------------------------------------------------------------------
*/

it('registers every shipped widget', function (): void {
    expect(app(WidgetRegistry::class)->all())->toHaveCount(15);
});

/**
 * Laravel's mergeConfigFrom merges top-level keys only, so a deployer who
 * published config/cairn.php before this key existed would otherwise get a
 * dashboard that renders successfully and shows nothing at all — with no
 * error to explain it. Found by upgrading a real application, not by the suite.
 */
it('falls back to the shipped widgets when config predates the key', function (): void {
    config()->set('cairn.dashboard', [
        'enabled' => true,
        'driver' => 'blade',
        'path' => 'cairn',
        'middleware' => ['web'],
        // No 'widgets' key, exactly as an older published config would have.
    ]);

    expect(app(WidgetRegistry::class)->all())->toHaveCount(15);

    cairnTest()->get('/cairn')->assertOk()->assertSee('Top routes');
});

/**
 * An explicitly empty list is somebody choosing to show nothing, which is
 * different from never having been asked.
 */
it('honours an explicitly empty widget list', function (): void {
    config()->set('cairn.dashboard.widgets', []);

    expect(app(WidgetRegistry::class)->all())->toBe([]);

    cairnTest()->get('/cairn')->assertOk()->assertDontSee('Top routes');
});

it('shows nothing rather than failing when the widget list is not an array at all', function (): void {
    config()->set('cairn.dashboard.widgets', 'nonsense');

    expect(app(WidgetRegistry::class)->all())->toBe([]);
});

it('resolves a widget by key', function (): void {
    expect(app(WidgetRegistry::class)->find('top-routes'))->toBeInstanceOf(TopRoutes::class)
        ->and(app(WidgetRegistry::class)->find('nope'))->toBeNull();
});

/**
 * A typo in config should cost one panel, not the page.
 */
it('skips a widget class that cannot be resolved', function (): void {
    config()->set('cairn.dashboard.widgets', [TopRoutes::class, 'Divoto\Cairn\NotAWidget']);

    expect(app(WidgetRegistry::class)->all())->toHaveCount(1);
});

it('renders with a reordered widget list', function (): void {
    config()->set('cairn.dashboard.widgets', [TopRoutes::class]);

    cairnTest()->get('/cairn')->assertOk()->assertSee('Top routes')->assertDontSee('Browsers');
});

/**
 * The acceptance criterion: a widget's rendered numbers match a direct call to
 * the report builder. If these ever diverge, the dashboard is showing
 * something the API would contradict.
 */
it('renders the same numbers the report builder returns', function (): void {
    seedTraffic();

    $filters = new Filters(range: 'today');
    $widget = app(TopRoutes::class);

    $fromWidget = $widget->rows($filters);

    [$from, $to] = $filters->window();

    $fromBuilder = Cairn::report()
        ->between($from, $to)
        ->interval($filters->interval())
        ->metrics(Metric::Pageviews)
        ->groupBy(Dimension::Route)
        ->orderByDesc(Metric::Pageviews)
        ->limit(10)
        ->get();

    expect($fromWidget->count())->toBe($fromBuilder->count())
        ->and(row($fromWidget)->dimension('route'))->toBe(row($fromBuilder)->dimension('route'))
        ->and(row($fromWidget)->metric(Metric::Pageviews))
        ->toBe(row($fromBuilder)->metric(Metric::Pageviews));
});

/**
 * One widget asking for something unavailable must not take the dashboard
 * down — the other fourteen are still worth showing.
 */
it('contains a failing widget to its own panel', function (): void {
    seedTraffic();

    config()->set('cairn.dashboard.widgets', [BrokenWidget::class, TopRoutes::class]);

    cairnTest()->get('/cairn')
        ->assertOk()
        ->assertSee('Top routes')
        ->assertSee('Nothing recorded in this period');
});

/*
|--------------------------------------------------------------------------
| The invariant
|--------------------------------------------------------------------------
*/

/**
 * Project rule: the dashboard never scans raw entries. This is what keeps it fast
 * as the raw table grows, and what makes the short retention window safe.
 */
it('issues no query against cairn_entries', function (): void {
    seedTraffic();

    $touched = [];

    app(DatabaseManager::class)->connection(Tables::connection())->listen(
        function (QueryExecuted $query) use (&$touched): void {
            if (str_contains($query->sql, Tables::entries())) {
                $touched[] = $query->sql;
            }
        }
    );

    cairnTest()->get('/cairn')->assertOk();

    expect($touched)->toBe([]);
});

it('shows live visitors from presence', function (): void {
    app(Presence::class)->touch(random_bytes(16), '/pricing');

    cairnTest()->get('/cairn')
        ->assertOk()
        ->assertSee('Right now')
        ->assertSee('visitor in the last 5 minutes');
});

/**
 * A live feed is a small dataset, and a small dataset with a rich record per
 * row is where individuals stop being anonymous.
 */
it('shows only a shortened hash and a page in the activity feed', function (): void {
    app(Presence::class)->touch(random_bytes(16), '/pricing');

    $html = asString(cairnTest()->get('/cairn')->assertOk()->getContent());

    expect($html)->toContain('Active pages')
        ->and($html)->toContain('identifies nobody');
});
