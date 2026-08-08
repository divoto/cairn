<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Divoto\Cairn\Contracts\Presence;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Contracts\UniqueCounter;
use Divoto\Cairn\Data\Entry;
use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\EntryType;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Enums\Period;
use Divoto\Cairn\Facades\Cairn;
use Divoto\Cairn\Reporting\Report;
use Divoto\Cairn\Support\Tables;
use Divoto\Cairn\Widgets\Filters;
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
 * CLAUDE.md: the dashboard never scans raw entries. This is what keeps it fast
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
