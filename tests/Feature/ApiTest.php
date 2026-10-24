<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Divoto\Cairn\CairnServiceProvider;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Contracts\UniqueCounter;
use Divoto\Cairn\Data\Entry;
use Divoto\Cairn\Data\ReportRow;
use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\EntryType;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Enums\Period;
use Divoto\Cairn\Facades\Cairn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Off by default. Routes are registered regardless and guarded per
    // request, so switching it on here is enough — see EnsureApiEnabled.
    config()->set('cairn.api.enabled', true);
});

function seedApiTraffic(): void
{
    $at = CarbonImmutable::now('UTC')->startOfDay()->addHours(9);

    foreach (['pricing.index', 'pricing.index', 'home.index'] as $index => $route) {
        /** @var Collection<int, Entry> $collection */
        $collection = new Collection([new Entry(
            occurredAt: $at->addMinutes($index),
            type: EntryType::Pageview,
            visitor: random_bytes(16),
            route: $route,
            url: '/'.$route,
            country: 'GB',
        )]);

        app(Storage::class)->store($collection);
    }

    app(Storage::class)->rollup($at->startOfDay(), $at->endOfDay(), Period::Day);
    app(Storage::class)->rollup($at->startOfDay(), $at->endOfDay(), Period::Hour);
}

/*
|--------------------------------------------------------------------------
| Availability
|--------------------------------------------------------------------------
|
| The API reads everything the dashboard can, so it stays off until the
| deployer decides who may call it.
|
*/

it('is unreachable when disabled', function (): void {
    config()->set('cairn.api.enabled', false);

    cairnTest()->getJson('/cairn/api/report')->assertNotFound();
});

/**
 * The availability check runs before the deployer's middleware. After it,
 * auth answers first — a 401 that says something is there, or a 500 when the
 * configured guard does not exist, as `auth:sanctum` does not without Sanctum.
 */
it('is unreachable when disabled, whatever auth middleware it sits behind', function (bool $guardExists): void {
    if ($guardExists) {
        config()->set('auth.guards.sanctum', ['driver' => 'session', 'provider' => 'users']);
    }

    config()->set('cairn.api.middleware', ['api', 'auth:sanctum']);
    config()->set('cairn.api.enabled', false);

    // Registered again with the shipped middleware: the test application's
    // own registration drops auth:sanctum, because Sanctum is not installed.
    $register = new ReflectionMethod(CairnServiceProvider::class, 'registerApi');
    $register->invoke(new CairnServiceProvider(app()));
    app('router')->getRoutes()->refreshNameLookups();

    cairnTest()->getJson('/cairn/api/report')->assertNotFound();
})->with([
    'without the guard' => [false],
    'with the guard' => [true],
]);

it('is reachable when enabled', function (): void {
    cairnTest()->getJson('/cairn/api/report?range=today')->assertOk();
});

/*
|--------------------------------------------------------------------------
| Shape
|--------------------------------------------------------------------------
*/

it('returns rows and meta', function (): void {
    seedApiTraffic();

    cairnTest()->getJson('/cairn/api/report?range=today')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['type', 'attributes']],
            'meta' => ['from', 'to', 'interval', 'approximate'],
        ]);
});

it('groups by a dimension', function (): void {
    seedApiTraffic();

    $response = cairnTest()
        ->getJson('/cairn/api/report?range=today&group_by=route&metrics=pageviews')
        ->assertOk();

    $data = $response->json('data');

    expect($data)->toHaveCount(2);
});

it('returns a timeseries when asked', function (): void {
    seedApiTraffic();

    $response = cairnTest()
        ->getJson('/cairn/api/report?range=today&timeseries=1')
        ->assertOk();

    // A day charted by hour.
    expect($response->json('data'))->toHaveCount(24)
        ->and($response->json('meta.interval'))->toBe('hour');
});

it('accepts several metrics', function (): void {
    seedApiTraffic();

    $response = cairnTest()
        ->getJson('/cairn/api/report?range=today&metrics=pageviews,sessions')
        ->assertOk();

    expect($response->json('data.0.attributes.metrics'))
        ->toHaveKey(Metric::Pageviews->value);
});

it('reports the interval it charted at', function (): void {
    cairnTest()->getJson('/cairn/api/report?range=7d&interval=hour&timeseries=1')
        ->assertOk()
        ->assertJsonPath('meta.interval', 'hour');
});

/**
 * A limit keeps the top rows, ranked by the first metric asked for. The
 * least-visited route is stored first and sorts first alphabetically, so a
 * limit applied in storage or key order would keep it.
 */
it('ranks grouped rows before applying a limit', function (): void {
    $at = CarbonImmutable::now('UTC')->startOfDay()->addHours(9);

    foreach (['about.index' => 1, 'pricing.index' => 3, 'home.index' => 2] as $route => $visits) {
        for ($i = 0; $i < $visits; $i++) {
            /** @var Collection<int, Entry> $collection */
            $collection = new Collection([new Entry(
                occurredAt: $at->addMinutes($i),
                type: EntryType::Pageview,
                visitor: random_bytes(16),
                route: $route,
                url: '/'.$route,
            )]);

            app(Storage::class)->store($collection);
        }
    }

    app(Storage::class)->rollup($at->startOfDay(), $at->endOfDay(), Period::Day);
    app(Storage::class)->rollup($at->startOfDay(), $at->endOfDay(), Period::Hour);

    $response = cairnTest()
        ->getJson('/cairn/api/report?range=today&group_by=route&metrics=pageviews&limit=2')
        ->assertOk();

    expect($response->json('data.*.attributes.dimensions.route'))
        ->toBe(['pricing.index', 'home.index']);
});

it('caps an absurd limit rather than honouring it', function (): void {
    seedApiTraffic();

    cairnTest()->getJson('/cairn/api/report?range=today&group_by=route&limit=999999')
        ->assertOk();
});

/**
 * A client charting these needs to know when a figure is an estimate, so the
 * flag is surfaced in meta rather than buried in each row.
 */
it('surfaces the approximate flag', function (): void {
    app(UniqueCounter::class)
        ->add(CarbonImmutable::now('UTC')->format('Y-m-d'), 'overall', random_bytes(16));

    $response = cairnTest()
        ->getJson('/cairn/api/report?range=30d&metrics=visitors')
        ->assertOk();

    expect($response->json('meta.approximate'))->toBeTrue();
});

it('narrows to a dimension filter', function (): void {
    seedApiTraffic();

    $response = cairnTest()
        ->getJson('/cairn/api/report?range=today&route=home.index')
        ->assertOk();

    expect($response->json('data.0.attributes.metrics.pageviews'))->toEqual(1);
});

/**
 * Stored as one key holding a status, a URL and a referring host. A client
 * reads the three as fields, not as JSON to parse out of a string.
 */
it('returns a broken page as an object', function (): void {
    /** @var Collection<int, Entry> $collection */
    $collection = new Collection([new Entry(
        occurredAt: CarbonImmutable::now('UTC')->startOfDay()->addHours(9),
        type: EntryType::NotFound,
        visitor: random_bytes(16),
        url: '/does-not-exist',
        referrerHost: 'blog.example.com',
        status: 404,
    )]);

    app(Storage::class)->store($collection);

    $today = CarbonImmutable::now('UTC');

    app(Storage::class)->rollup($today->startOfDay(), $today->endOfDay(), Period::Day);
    app(Storage::class)->rollup($today->startOfDay(), $today->endOfDay(), Period::Hour);

    cairnTest()
        ->getJson('/cairn/api/report?range=today&group_by=error_page&metrics=errors')
        ->assertOk()
        ->assertJsonPath('data.0.attributes.dimensions.error_page', [
            'status' => 404,
            'url' => '/does-not-exist',
            'referrer' => 'blog.example.com',
        ]);
});

/*
|--------------------------------------------------------------------------
| Refusals
|--------------------------------------------------------------------------
*/

/**
 * Ignoring what it cannot read would answer a different question than the
 * one asked — `period=7d` with Today, `group_by=bogus` with the site-wide
 * total — and nothing in the response would say so.
 */
it('refuses a parameter it cannot read', function (string $query, string $parameter): void {
    cairnTest()
        ->getJson('/cairn/api/report?'.$query)
        ->assertStatus(422)
        ->assertJsonPath('errors.0.title', 'Invalid parameter')
        ->assertJsonPath('errors.0.source.parameter', $parameter);
})->with([
    'an unknown parameter' => ['period=7d', 'period'],
    'an unknown range' => ['range=week', 'range'],
    'an unknown comparison' => ['compare=yesterday', 'compare'],
    'an unknown interval' => ['interval=minute', 'interval'],
    'an unknown dimension' => ['group_by=bogus', 'group_by'],
    'an unknown metric' => ['metrics=pageviews,nonsense', 'metrics'],
    'a limit of zero' => ['limit=0', 'limit'],
    'a limit that is not a number' => ['limit=ten', 'limit'],
    'a timeseries flag that is not a boolean' => ['timeseries=maybe', 'timeseries'],
    'a parameter given as an array' => ['range[]=7d', 'range'],
]);

it('names every unreadable parameter at once', function (): void {
    $response = cairnTest()
        ->getJson('/cairn/api/report?period=7d&group_by=bogus')
        ->assertStatus(422);

    expect($response->json('errors.*.source.parameter'))->toBe(['period', 'group_by'])
        ->and($response->json('errors.0.detail'))->toContain('range');
});

it('refuses two dimension filters at once', function (): void {
    cairnTest()
        ->getJson('/cairn/api/report?range=today&route=home.index&country=GB')
        ->assertStatus(422)
        ->assertJsonPath('errors.0.title', 'Unavailable combination');
});

/**
 * A 422 naming the combination, rather than an empty result the caller has to
 * work backwards from.
 */
it('explains an unavailable combination rather than returning nothing', function (): void {
    $response = cairnTest()
        ->getJson('/cairn/api/report?range=today&group_by=url')
        ->assertStatus(422);

    expect($response->json('errors.0.detail'))->toContain('url')
        ->and($response->json('errors.0.title'))->toBe('Unavailable combination');
});

it('explains a refused metric', function (): void {
    cairnTest()
        ->getJson('/cairn/api/report?range=today&group_by=country&metrics=visitors')
        ->assertStatus(422)
        ->assertJsonPath('errors.0.status', '422');
});

/*
|--------------------------------------------------------------------------
| Agreement with the dashboard
|--------------------------------------------------------------------------
|
| Both go through the same report builder, so they cannot disagree. This test
| is what makes that guarantee visible rather than merely structural.
|
*/

it('returns the same numbers the report builder does', function (): void {
    seedApiTraffic();

    $response = cairnTest()
        ->getJson('/cairn/api/report?range=today&group_by=route&metrics=pageviews')
        ->assertOk();

    $fromBuilder = Cairn::report()
        ->between(
            CarbonImmutable::now('UTC')->startOfDay(),
            CarbonImmutable::now('UTC')->endOfDay(),
        )
        ->interval(Period::Hour)
        ->metrics(Metric::Pageviews)
        ->groupBy(Dimension::Route)
        ->get();

    $rows = $response->json('data');
    $viaApi = [];

    foreach (is_array($rows) ? $rows : [] as $row) {
        $attributes = is_array($row) ? ($row['attributes'] ?? null) : null;
        $metrics = is_array($attributes) ? ($attributes['metrics'] ?? null) : null;

        $viaApi[] = columnFloat(is_array($metrics) ? ($metrics['pageviews'] ?? 0) : 0);
    }

    sort($viaApi);

    $direct = $fromBuilder
        ->map(static fn (ReportRow $row): float => $row->metric(Metric::Pageviews) ?? 0.0)
        ->values()
        ->all();

    sort($direct);

    expect($viaApi)->toBe($direct);
});
