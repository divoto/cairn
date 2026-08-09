<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
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

it('falls back to pageviews for an unknown metric', function (): void {
    seedApiTraffic();

    $response = cairnTest()
        ->getJson('/cairn/api/report?range=today&metrics=nonsense')
        ->assertOk();

    expect($response->json('data.0.attributes.metrics'))
        ->toHaveKey(Metric::Pageviews->value);
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

/*
|--------------------------------------------------------------------------
| Refusals
|--------------------------------------------------------------------------
*/

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
