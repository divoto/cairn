<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Divoto\Cairn\Data\Entry;
use Divoto\Cairn\Enums\EntryType;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Enums\Period;
use Divoto\Cairn\Enums\RouteGrouping;
use Divoto\Cairn\Support\ChannelClassifier;
use Divoto\Cairn\Support\Engine;
use Divoto\Cairn\Support\EntryMapper;
use Divoto\Cairn\Support\Format;
use Divoto\Cairn\Support\RouteNameGrouper;
use Divoto\Cairn\Support\SubjectKey;
use Divoto\Cairn\Support\Tables;
use Divoto\Cairn\Widgets\WidgetLayout;
use Divoto\Cairn\Widgets\WidgetSchema;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/*
|--------------------------------------------------------------------------
| Format
|--------------------------------------------------------------------------
|
| Formatting lives outside the views so Blade, the JSON API and a CSV export
| make the same decision from the same fact, and the rounding rules sit in one
| place rather than in fourteen templates.
|
*/

/**
 * A null is genuinely different from a zero here: a bounce rate over zero
 * sessions is unknown, not 0%, and printing "0%" would be a claim the data
 * does not support.
 */
it('renders an unknown metric as a dash rather than a zero', function (): void {
    expect(Format::metric(Metric::BounceRate, null))->toBe('—')
        ->and(Format::metric(Metric::Pageviews, null))->toBe('—')
        ->and(Format::metric(Metric::BounceRate, 0.0))->toBe('0.0%');
});

it('renders each unit in its own way', function (): void {
    expect(Format::metric(Metric::BounceRate, 0.612))->toBe('61.2%')
        ->and(Format::metric(Metric::ConversionRate, 0.05))->toBe('5.0%')
        ->and(Format::metric(Metric::ConversionValue, 1234.5))->toBe('1,234.50')
        ->and(Format::metric(Metric::ViewsPerSession, 2.4567))->toBe('2.46')
        ->and(Format::metric(Metric::Pageviews, 4109.0))->toBe('4,109');
});

/**
 * A dashboard column is narrow, and "1.2M" is read faster than "1,238,411" —
 * which nobody reads to the last digit anyway.
 */
it('abbreviates a count once it stops fitting', function (float $value, string $expected): void {
    expect(Format::count($value))->toBe($expected);
})->with([
    [0.0, '0'],
    [999.0, '999'],
    [9999.0, '9,999'],
    [10000.0, '10k'],
    [12500.0, '12.5k'],
    [999999.0, '1,000k'],
    [1000000.0, '1M'],
    [1238411.0, '1.2M'],
]);

it('renders a duration in the unit that reads best', function (float $seconds, string $expected): void {
    expect(Format::duration($seconds))->toBe($expected);
})->with([
    [0.0, '0s'],
    [45.0, '45s'],
    [59.4, '59s'],
    [60.0, '1m 0s'],
    [125.0, '2m 5s'],
    [3600.0, '1h 0m'],
    [7325.0, '2h 2m'],
]);

it('renders server timings in milliseconds or seconds', function (): void {
    expect(Format::milliseconds(42.0))->toBe('42ms')
        ->and(Format::milliseconds(999.0))->toBe('999ms')
        ->and(Format::milliseconds(1000.0))->toBe('1s')
        ->and(Format::milliseconds(2500.0))->toBe('2.5s');
});

it('formats every metric without erroring', function (Metric $metric): void {
    expect(Format::metric($metric, 12.5))->not->toBe('');
})->with(array_map(static fn (Metric $m): array => [$m], Metric::cases()));

/**
 * A bucket label has to name the part of the timestamp that varies across the
 * series. Formatting 24 hourly buckets as dates prints today's date 24 times.
 */
it('labels a bucket at the granularity it was measured at', function (Period $interval, string $expected): void {
    $bucket = CarbonImmutable::parse('2026-03-14 09:00:00', 'UTC');

    expect(Format::bucket($bucket, $interval))->toBe($expected);
})->with([
    'hour' => [Period::Hour, '09:00'],
    'day' => [Period::Day, 'Mar 14, 2026'],
    'month' => [Period::Month, 'Mar 2026'],
]);

it('renders a missing bucket as an em dash', function (): void {
    expect(Format::bucket(null, Period::Day))->toBe('—');
});

/*
|--------------------------------------------------------------------------
| RouteNameGrouper
|--------------------------------------------------------------------------
|
| The thing a browser tag cannot do. Without it, one busy route fragments into
| thousands of one-visit URLs and nothing aggregates.
|
*/

it('collapses identifier-looking segments', function (string $path, string $expected): void {
    expect((new RouteNameGrouper)->collapse($path))->toBe($expected);
})->with([
    'numeric' => ['/orders/8814/invoice', '/orders/{id}/invoice'],
    'several' => ['/users/12/orders/99', '/users/{id}/orders/{id}'],
    'uuid' => ['/files/3f2504e0-4f89-41d3-9a0c-0305e82c3301', '/files/{id}'],
    'ulid' => ['/files/01ARZ3NDEKTSV4RRFFQ69G5FAV', '/files/{id}'],
    'none to collapse' => ['/pricing', '/pricing'],
    'nested' => ['/docs/getting-started/install', '/docs/getting-started/install'],
    'root' => ['/', '/'],
    'no leading slash' => ['pricing', '/pricing'],
]);

/**
 * A query string is where reset tokens, search terms and session identifiers
 * live. An entry recording one would be storing a credential.
 */
it('keeps only campaign parameters in the stored URL', function (): void {
    $grouper = new RouteNameGrouper;

    expect($grouper->url(Request::create('/pricing')))->toBe('/pricing')
        ->and($grouper->url(Request::create('/pricing?token=secret')))->toBe('/pricing')
        ->and($grouper->url(Request::create('/pricing?utm_source=news&token=secret')))
        ->toBe('/pricing?utm_source=news');
});

it('sorts campaign parameters so the same visit is one URL', function (): void {
    $grouper = new RouteNameGrouper;

    expect($grouper->url(Request::create('/p?utm_medium=email&utm_source=news')))
        ->toBe($grouper->url(Request::create('/p?utm_source=news&utm_medium=email')));
});

it('falls back to a collapsed path when there is no route', function (): void {
    expect((new RouteNameGrouper)->group(Request::create('/orders/1/invoice')))
        ->toBe('/orders/{id}/invoice');
});

/**
 * The reason the grouping is configurable at all: a site that serves its whole
 * catalogue from one named route has the same route name for every page, and
 * the default grouping puts all of it on a single row.
 */
it('groups by the configured strategy', function (RouteGrouping $grouping, string $expected): void {
    $route = (new Route('GET', '{page}', []))->name('pages.show');
    $request = Request::create('/blog/hello-world');
    $request->setRouteResolver(fn (): Route => $route);

    expect((new RouteNameGrouper($grouping))->group($request))->toBe($expected);
})->with([
    'name' => [RouteGrouping::Name, 'pages.show'],
    'uri' => [RouteGrouping::Uri, '/{page}'],
    'path' => [RouteGrouping::Path, '/blog/hello-world'],
]);

it('still collapses identifiers when grouping by path', function (): void {
    $route = (new Route('GET', 'orders/{order}/invoice', []))->name('orders.invoice');
    $request = Request::create('/orders/8814/invoice');
    $request->setRouteResolver(fn (): Route => $route);

    expect((new RouteNameGrouper(RouteGrouping::Path))->group($request))
        ->toBe('/orders/{id}/invoice');
});

it('falls back past an unnamed route when grouping by name', function (): void {
    $route = new Route('GET', 'orders/{order}', []);
    $request = Request::create('/orders/8814');
    $request->setRouteResolver(fn (): Route => $route);

    expect((new RouteNameGrouper)->group($request))->toBe('/orders/{order}');
});

it('reads the grouping from config, defaulting to route names', function (): void {
    expect(RouteGrouping::fromConfig('path'))->toBe(RouteGrouping::Path)
        ->and(RouteGrouping::fromConfig('name'))->toBe(RouteGrouping::Name)
        ->and(RouteGrouping::fromConfig(null))->toBe(RouteGrouping::Name)
        ->and(RouteGrouping::fromConfig('nonsense'))->toBe(RouteGrouping::Name)
        ->and(RouteGrouping::fromConfig(['path']))->toBe(RouteGrouping::Name);
});

it('describes what the routes table is showing for each strategy', function (RouteGrouping $grouping): void {
    expect($grouping->description())->toBeString();
    expect($grouping->description())->not->toBe('');
})->with([
    'name' => [RouteGrouping::Name],
    'uri' => [RouteGrouping::Uri],
    'path' => [RouteGrouping::Path],
]);

/*
|--------------------------------------------------------------------------
| ChannelClassifier
|--------------------------------------------------------------------------
*/

it('reads only the referrer host, never the path', function (): void {
    $request = Request::create('/pricing', 'GET', server: [
        'HTTP_REFERER' => 'https://news.ycombinator.com/item?id=1&secret=x',
    ]);

    expect((new ChannelClassifier)->referrerHost($request))->toBe('news.ycombinator.com');
});

it('ignores an unparseable referrer', function (string $referrer): void {
    $request = Request::create('/pricing', 'GET', server: ['HTTP_REFERER' => $referrer]);

    expect((new ChannelClassifier)->referrerHost($request))->toBeNull();
})->with([
    'empty' => [''],
    'not a url' => ['nonsense'],
    'scheme only' => ['https://'],
]);

it('caps a campaign value read from a URL', function (): void {
    $request = Request::create('/pricing?utm_source='.str_repeat('a', 500));

    expect(strlen((new ChannelClassifier)->campaign($request)['utm_source'] ?? ''))->toBe(128);
});

it('returns null for campaign fields that are absent', function (): void {
    $campaign = (new ChannelClassifier)->campaign(Request::create('/pricing'));

    expect($campaign['utm_source'])->toBeNull()
        ->and($campaign['utm_term'])->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Engine
|--------------------------------------------------------------------------
*/

it('knows which engines can partition and which need a composite key', function (): void {
    expect(Engine::supportsPartitioning('mysql'))->toBeTrue()
        ->and(Engine::supportsPartitioning('sqlite'))->toBeFalse()
        ->and(Engine::usesCompositeTimeKey('mariadb'))->toBeTrue()
        ->and(Engine::usesCompositeTimeKey('pgsql'))->toBeFalse();
});

/**
 * All four do, which is why no Cairn table puts a nullable column in a unique
 * index — two rows differing only by a NULL would both be accepted.
 */
it('treats every supported engine as making NULLs distinct in a unique index', function (string $driver): void {
    expect(Engine::treatsNullsAsDistinctInUniqueIndexes($driver))->toBeTrue();
})->with([['mysql'], ['mariadb'], ['pgsql'], ['sqlite']]);

it('makes no claim about an engine it does not support', function (): void {
    expect(Engine::treatsNullsAsDistinctInUniqueIndexes('oracle'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Tables
|--------------------------------------------------------------------------
*/

it('applies the configured prefix to every table', function (): void {
    config()->set('cairn.table_prefix', 'analytics_');

    expect(Tables::prefix())->toBe('analytics_')
        ->and(Tables::entries())->toBe('analytics_entries')
        ->and(Tables::sessions())->toBe('analytics_sessions')
        ->and(Tables::aggregates())->toBe('analytics_aggregates')
        ->and(Tables::visitorDays())->toBe('analytics_visitor_days')
        ->and(Tables::presence())->toBe('analytics_presence')
        ->and(Tables::all())->toHaveCount(5);
});

it('falls back to the default prefix when configuration is nonsense', function (): void {
    config()->set('cairn.table_prefix', ['not', 'a', 'string']);

    expect(Tables::prefix())->toBe('cairn_');
});

it('treats an empty connection as the application default', function (): void {
    config()->set('cairn.connection', '');

    expect(Tables::connection())->toBeNull();
});

/**
 * Aggregates are excluded: they hold counts rather than records of people, and
 * are kept forever unless the deployer sets a retention window.
 */
it('lists only the visitor-level tables as raw', function (): void {
    expect(Tables::raw())->toHaveCount(4);
    expect(Tables::raw())->not->toContain(Tables::aggregates());
});

/*
|--------------------------------------------------------------------------
| EntryMapper
|--------------------------------------------------------------------------
*/

it('rejects a payload that is not an entry', function (string $payload): void {
    expect(EntryMapper::deserialise($payload))->toBeNull();
})->with([
    'not json' => ['nonsense'],
    'not an object' => ['[1,2,3]'],
    'no type' => ['{"occurred_at":"2026-03-14 09:00:00","visitor":"abc"}'],
    'unknown type' => ['{"occurred_at":"2026-03-14 09:00:00","type":"nope","visitor":"YWJj"}'],
    'no visitor' => ['{"occurred_at":"2026-03-14 09:00:00","type":"pageview"}'],
]);

/**
 * A property bag holds a handful of labels and numbers. Anything deeper is
 * either a mistake or an attempt to store a record.
 */
it('drops nested structures from custom properties', function (): void {
    $entry = new Entry(
        occurredAt: now()->toImmutable(),
        type: EntryType::Event,
        visitor: random_bytes(16),
        name: 'signed_up',
        properties: ['plan' => 'pro', 'seats' => 5, 'ok' => true],
    );

    $restored = EntryMapper::deserialise(EntryMapper::serialise($entry));

    expect($restored?->properties)->toBe(['plan' => 'pro', 'seats' => 5, 'ok' => true]);
});

it('round-trips a session hash alongside the visitor hash', function (): void {
    $session = random_bytes(16);

    $entry = new Entry(
        occurredAt: now()->toImmutable()->startOfSecond(),
        type: EntryType::Pageview,
        visitor: random_bytes(16),
        session: $session,
    );

    $restored = EntryMapper::deserialise(EntryMapper::serialise($entry));

    expect($restored?->session)->toBe($session);
});

it('round-trips an entry with nothing but the essentials', function (): void {
    $visitor = random_bytes(16);

    $entry = new Entry(
        occurredAt: now()->toImmutable()->startOfSecond(),
        type: EntryType::Pageview,
        visitor: $visitor,
    );

    $restored = EntryMapper::deserialise(EntryMapper::serialise($entry));

    expect($restored)->not->toBeNull()
        ->and($restored?->visitor)->toBe($visitor)
        ->and($restored?->type)->toBe(EntryType::Pageview)
        ->and($restored?->session)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| WidgetSchema
|--------------------------------------------------------------------------
*/

/**
 * Reporting "0 seconds average time on page" when nothing is measuring it is
 * worse than saying nothing.
 */
it('explains a beacon-only widget rather than showing a zero', function (): void {
    $schema = new WidgetSchema(layout: WidgetLayout::Table, requiresBeacon: true);

    expect($schema->emptyMessage())->toContain('JavaScript beacon');
});

it('uses a widget\'s own empty message when it has one', function (): void {
    $schema = new WidgetSchema(layout: WidgetLayout::Table, empty: 'Nothing here.');

    expect($schema->emptyMessage())->toBe('Nothing here.');
});

it('falls back to a plain empty message', function (): void {
    expect((new WidgetSchema(layout: WidgetLayout::Table))->emptyMessage())
        ->toBe('Nothing recorded in this period.');
});

/*
|--------------------------------------------------------------------------
| SubjectKey
|--------------------------------------------------------------------------
*/

it('splits a subject key into its type and id', function (): void {
    expect(SubjectKey::parse('article:42'))->toBe(['type' => 'article', 'id' => '42'])
        // A class name carries its own colons in no version of PHP, but a
        // morph alias is free-form, so the *last* separator is the one.
        ->and(SubjectKey::parse('App\\Models\\Article:42'))
        ->toBe(['type' => 'App\\Models\\Article', 'id' => '42']);
});

/**
 * Aggregate rows are read back from the database, where a key could have been
 * written by an older version, edited by hand, or corrupted. The widget skips
 * what it cannot parse rather than throwing and taking the panel down.
 */
it('refuses a subject key that names no model', function (string $key): void {
    expect(SubjectKey::parse($key))->toBeNull();
})->with([
    'no separator' => ['article'],
    'empty' => [''],
    'nothing before it' => [':42'],
    'nothing after it' => ['article:'],
]);
