<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Divoto\Cairn\Cairn;
use Divoto\Cairn\Contracts\BotDetector;
use Divoto\Cairn\Contracts\Presence;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Data\AggregateQuery;
use Divoto\Cairn\Enums\Comparison;
use Divoto\Cairn\Enums\DeviceType;
use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Enums\OperatingSystem;
use Divoto\Cairn\Enums\Period;
use Divoto\Cairn\Ingest\DatabaseIngest;
use Divoto\Cairn\Recorders\ClientMetrics;
use Divoto\Cairn\Recording\EntryFactory;
use Divoto\Cairn\Reporting\Report;
use Divoto\Cairn\Support\Assets;
use Divoto\Cairn\Support\Beacon;
use Divoto\Cairn\Support\Tables;
use Divoto\Cairn\Widgets\DimensionWidget;
use Divoto\Cairn\Widgets\Filters;
use Divoto\Cairn\Widgets\Shipped\ActivityFeed;
use Divoto\Cairn\Widgets\Shipped\Browsers;
use Divoto\Cairn\Widgets\Shipped\Countries;
use Divoto\Cairn\Widgets\Shipped\LiveVisitors;
use Divoto\Cairn\Widgets\Shipped\TopRoutes;
use Divoto\Cairn\Widgets\WidgetLayout;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AggregateQuery
|--------------------------------------------------------------------------
|
| The narrow contract between the report builder and storage. The builder
| speaks a forgiving API; storage receives this, already resolved, validated
| and tenant-scoped.
|
*/

it('knows when a query asks for site-wide totals', function (): void {
    $base = new AggregateQuery(
        from: CarbonImmutable::parse('2026-03-14', 'UTC'),
        to: CarbonImmutable::parse('2026-03-15', 'UTC'),
        period: Period::Day,
        metrics: [Metric::Pageviews],
    );

    expect($base->isOverall())->toBeTrue()
        ->and($base->groupBy)->toBe([]);

    $grouped = new AggregateQuery(
        from: $base->from,
        to: $base->to,
        period: Period::Day,
        metrics: [Metric::Pageviews],
        groupBy: [Dimension::Route],
    );

    expect($grouped->isOverall())->toBeFalse();
});

/**
 * Applied by the report builder from the TenantResolver, never by a caller —
 * tenant isolation that depends on every call site remembering to ask for it
 * is not isolation.
 */
it('scopes a query to a tenant without losing anything else', function (): void {
    $query = new AggregateQuery(
        from: CarbonImmutable::parse('2026-03-14', 'UTC'),
        to: CarbonImmutable::parse('2026-03-15', 'UTC'),
        period: Period::Day,
        metrics: [Metric::Pageviews, Metric::Sessions],
        groupBy: [Dimension::Route],
        filters: ['route' => ['pricing.index']],
        orderBy: Metric::Pageviews,
        descending: false,
        limit: 5,
    );

    $scoped = $query->forTenant('acme');

    expect($scoped->tenantId)->toBe('acme')
        ->and($scoped->metrics)->toBe($query->metrics)
        ->and($scoped->groupBy)->toBe($query->groupBy)
        ->and($scoped->filters)->toBe($query->filters)
        ->and($scoped->orderBy)->toBe(Metric::Pageviews)
        ->and($scoped->descending)->toBeFalse()
        ->and($scoped->limit)->toBe(5)
        ->and($query->tenantId)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

it('adds and clears a dimension filter', function (): void {
    $filters = new Filters(range: '7d');

    $withRoute = $filters->with('route', 'pricing.index');

    expect($withRoute->route)->toBe('pricing.index')
        ->and($withRoute->range)->toBe('7d')
        ->and($withRoute->isFiltered())->toBeTrue()
        ->and($withRoute->with('route', null)->isFiltered())->toBeFalse()
        ->and($filters->isFiltered())->toBeFalse();
});

it('carries each filter into the query string and drops the empty ones', function (): void {
    $filters = (new Filters(range: '7d'))->with('country', 'GB');

    $query = $filters->toQuery();

    expect($query)->toHaveKey('country')
        ->and($query['country'])->toBe('GB')
        ->and($query)->not->toHaveKey('route')
        ->and($filters->toQuery(['country' => null]))->not->toHaveKey('country');
});

it('names every range it offers', function (): void {
    foreach (array_keys(Filters::ranges()) as $range) {
        $filters = new Filters(range: $range);

        [$from, $to] = $filters->window();

        expect($filters->rangeLabel())->not->toBe('')
            ->and($from->lessThan($to))->toBeTrue();
    }
});

it('falls back to a default label for an unknown range', function (): void {
    expect((new Filters(range: 'nonsense'))->rangeLabel())->toBe('Last 30 days');
});

it('reads a comparison out of the query string', function (): void {
    $filters = Filters::fromRequest(Request::create('/cairn?compare=previous_year'));

    expect($filters->comparison)->toBe(Comparison::PreviousYear);
});

it('falls back to the default comparison for an unknown one', function (): void {
    expect(Filters::fromRequest(Request::create('/cairn?compare=nonsense'))->comparison)
        ->toBe(Comparison::PreviousPeriod);
});

/*
|--------------------------------------------------------------------------
| Widgets
|--------------------------------------------------------------------------
*/

it('describes the live-visitor and activity widgets', function (): void {
    $live = app(LiveVisitors::class);
    $feed = app(ActivityFeed::class);

    expect($live->key())->toBe('live-visitors')
        ->and($live->title())->toBe('Right now')
        ->and($live->schema()->layout)->toBe(WidgetLayout::Stat)
        ->and($live->schema()->emptyMessage())->toContain('Nobody')
        ->and($feed->key())->toBe('activity-feed')
        ->and($feed->schema()->layout)->toBe(WidgetLayout::Feed)
        ->and($feed->description())->toContain('identifies anyone');
});

it('shortens the visitor hash in the activity feed', function (): void {
    app(Presence::class)->touch(random_bytes(16), '/pricing');

    $row = row(app(ActivityFeed::class)->rows(new Filters(range: 'today')));

    // Eight characters: enough to tell two rows apart, meaningless as an
    // identifier, and it rotates daily anyway.
    expect(strlen((string) $row->dimension('visitor')))->toBe(8)
        ->and($row->dimension('page'))->toBe('/pricing');
});

it('gives both widgets a runnable query', function (): void {
    $filters = new Filters(range: 'today');

    expect(app(LiveVisitors::class)->query($filters))
        ->toBeInstanceOf(Report::class)
        ->and(app(ActivityFeed::class)->query($filters))
        ->toBeInstanceOf(Report::class);
});

/**
 * DimensionWidget supplies a key and a title from the dimension itself, so the
 * shortest custom widget is a class with one method. Every shipped widget
 * overrides both for nicer wording, which is why the defaults need a widget of
 * their own to be exercised.
 */
it('names a widget from its dimension when nothing overrides it', function (): void {
    $widget = new class(app(Cairn::class)) extends DimensionWidget
    {
        protected function dimension(): Dimension
        {
            return Dimension::Language;
        }
    };

    expect($widget->key())->toBe('language')
        ->and($widget->title())->toBe('Language')
        ->and($widget->schema()->dimension)->toBe('language')
        // Only the three dimensions the filter bar understands are clickable;
        // any other would produce a URL the rest of the dashboard cannot
        // honour, since v1 rolls up one dimension at a time.
        ->and($widget->schema()->filterAs)->toBeNull();
});

it('makes only the filterable dimensions clickable', function (): void {
    expect(app(TopRoutes::class)->schema()->filterAs)->toBe('route')
        ->and(app(Countries::class)->schema()->filterAs)->toBe('country')
        ->and(app(Browsers::class)->schema()->filterAs)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| DatabaseIngest
|--------------------------------------------------------------------------
*/

/**
 * A request producing more entries than the buffer holds is either a bug or an
 * attack, and neither is worth exhausting memory over.
 */
it('sheds entries past the configured buffer rather than growing', function (): void {
    config()->set('cairn.ingest.buffer', 3);

    $ingest = new DatabaseIngest(app(Repository::class));

    foreach (range(1, 10) as $ignored) {
        $ingest->record(anEntry());
    }

    expect($ingest->buffered())->toBe(3);
});

it('falls back to a sane buffer when configuration is nonsense', function (): void {
    config()->set('cairn.ingest.buffer', 'lots');

    $ingest = new DatabaseIngest(app(Repository::class));
    $ingest->record(anEntry());

    expect($ingest->buffered())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| EntryFactory
|--------------------------------------------------------------------------
*/

/**
 * The only place a user id is read. Off by default, and this is the line to
 * audit when it is not.
 */
it('records a user id only when explicitly opted in', function (): void {
    $user = new class extends User
    {
        protected $table = 'users';

        public function getAuthIdentifier(): int
        {
            return 77;
        }
    };

    $request = Request::create('/pricing');
    $request->setUserResolver(static fn (): object => $user);

    config()->set('cairn.privacy.track_user_id', false);
    expect(app(EntryFactory::class)->pageview($request)->userId)->toBeNull();

    config()->set('cairn.privacy.track_user_id', true);
    expect(app(EntryFactory::class)->pageview($request)->userId)->toBe(77);
});

it('records no user id when nobody is signed in', function (): void {
    config()->set('cairn.privacy.track_user_id', true);

    expect(app(EntryFactory::class)->pageview(Request::create('/pricing'))->userId)->toBeNull();
});

/**
 * "none" means no lookup happens at all, so a resolver cannot leak a location
 * into a column even by accident.
 */
it('skips the geo lookup entirely at a precision of none', function (): void {
    config()->set('cairn.privacy.geo_precision', 'none');

    $entry = app(EntryFactory::class)->pageview(Request::create('/pricing'));

    expect($entry->country)->toBeNull()
        ->and($entry->region)->toBeNull()
        ->and($entry->city)->toBeNull();
});

it('falls back to country precision for an unrecognised setting', function (): void {
    config()->set('cairn.privacy.geo_precision', 'nonsense');

    expect(fn () => app(EntryFactory::class)->pageview(Request::create('/pricing')))
        ->not->toThrow(Throwable::class);
});

/**
 * Only the first tag, and only eight characters. A full Accept-Language header
 * is a fingerprinting signal in its own right.
 */
it('records a short language tag or nothing at all', function (string $header, ?string $expected): void {
    $entry = app(EntryFactory::class)->pageview(
        Request::create('/pricing', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => $header])
    );

    expect($entry->language)->toBe($expected);
})->with([
    // No 'absent' case: Symfony's Request::create supplies a default
    // Accept-Language, so the header is never genuinely missing here.
    'simple' => ['en-GB', 'en-GB'],
    'weighted list' => ['en-GB,en;q=0.9,fr;q=0.8', 'en-GB'],
    'long' => ['zh-Hans-CN-x-private', 'zh-Hans-'],
    'empty' => ['', null],
]);

it('reads the low-entropy client hints a browser volunteers', function (): void {
    $entry = app(EntryFactory::class)->pageview(Request::create('/pricing', 'GET', server: [
        'HTTP_SEC_CH_UA_PLATFORM' => '"Android"',
        'HTTP_SEC_CH_UA_MOBILE' => '?1',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (Unknown)',
    ]));

    expect($entry->os)->toBe(OperatingSystem::Android)
        ->and($entry->deviceType)->toBe(DeviceType::Mobile);
});

/*
|--------------------------------------------------------------------------
| Assets and the beacon
|--------------------------------------------------------------------------
*/

/**
 * A deployer who wants to restyle the dashboard edits one file and their
 * version wins.
 */
it('prefers published assets over the packaged ones', function (): void {
    $directory = public_path('vendor/cairn');

    if (! is_dir($directory)) {
        mkdir($directory, 0o755, true);
    }

    file_put_contents($directory.'/cairn.css', '/* published */');
    file_put_contents($directory.'/cairn.min.js', 'window.published=1;');

    expect(Assets::css())->toBe('/* published */')
        ->and(Beacon::tag())->toContain('window.published=1;');

    unlink($directory.'/cairn.css');
    unlink($directory.'/cairn.min.js');
});

it('offers no CSP hash when the beacon is not being served', function (): void {
    config()->set('cairn.recorders.'.ClientMetrics::class.'.enabled', false);

    expect(Beacon::tag())->toBe('');
});

it('points the beacon at the configured dashboard path', function (): void {
    config()->set('cairn.dashboard.path', 'stats');

    expect(Beacon::endpoint())->toBe('/stats/collect');
});

it('falls back to the default path when it is misconfigured', function (): void {
    config()->set('cairn.dashboard.path', '');

    expect(Beacon::endpoint())->toBe('/cairn/collect');
});

/*
|--------------------------------------------------------------------------
| Bot detection
|--------------------------------------------------------------------------
*/

/**
 * A request with no user agent is not a browser. Treating it as one is how a
 * scripted flood ends up counted as traffic.
 */
it('treats a blank user agent as automated', function (?string $agent): void {
    expect(app(BotDetector::class)->isBot($agent))->toBeTrue();
})->with([[null], [''], ['   '], ["\t"]]);

it('recognises a browser', function (): void {
    expect(app(BotDetector::class)->isBot(
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/122.0.0.0 Safari/537.36'
    ))->toBeFalse();
});

it('recognises the crawlers, tools and unfurlers it knows about', function (string $agent): void {
    expect(app(BotDetector::class)->isBot($agent))->toBeTrue();
})->with([
    'googlebot' => ['Googlebot/2.1 (+http://www.google.com/bot.html)'],
    'curl' => ['curl/8.4.0'],
    'python' => ['python-requests/2.31.0'],
    'headless chrome' => ['Mozilla/5.0 HeadlessChrome/122.0.0.0'],
    'slack unfurler' => ['Slackbot-LinkExpanding 1.0'],
    'uptime monitor' => ['Mozilla/5.0 (compatible; UptimeRobot/2.0)'],
    'feed reader' => ['Feedfetcher-Google'],
]);

/*
|--------------------------------------------------------------------------
| Tenancy end to end
|--------------------------------------------------------------------------
*/

it('scopes a rollup and a report to a tenant', function (): void {
    $connection = app(DatabaseManager::class)->connection(Tables::connection());
    $at = CarbonImmutable::parse('2026-03-14 09:00:00', 'UTC');

    foreach ([['acme', 3], ['globex', 1]] as [$tenant, $count]) {
        foreach (range(1, $count) as $index) {
            $connection->table(Tables::entries())->insert([
                'occurred_at' => $at->addMinutes($index)->toDateTimeString(),
                'type' => 'pageview',
                'visitor' => binaryColumn(random_bytes(16)),
                'route' => 'pricing.index',
                'tenant_id' => $tenant,
            ]);
        }
    }

    app(Storage::class)->rollup($at->startOfDay(), $at->endOfDay(), Period::Day);

    $rows = $connection->table(Tables::aggregates())
        ->where('type', Metric::Pageviews->value)
        ->where('aggregate', 'overall')
        ->pluck('value', 'tenant_id');

    expect(columnFloat($rows['acme'] ?? null))->toBe(3.0)
        ->and(columnFloat($rows['globex'] ?? null))->toBe(1.0);
});
