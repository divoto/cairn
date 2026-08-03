<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Divoto\Cairn\Cairn;
use Divoto\Cairn\Contracts\Ingest;
use Divoto\Cairn\Contracts\Presence;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Contracts\UniqueCounter;
use Divoto\Cairn\Enums\Channel;
use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Maintenance\Eraser;
use Divoto\Cairn\Maintenance\Maintenance;
use Divoto\Cairn\Maintenance\Pruner;
use Divoto\Cairn\Support\Tables;
use Divoto\Cairn\Widgets\Filters;
use Divoto\Cairn\Widgets\Shipped\ActivityFeed;
use Divoto\Cairn\Widgets\Shipped\LiveVisitors;
use Divoto\Cairn\Widgets\Shipped\Overview;
use Divoto\Cairn\Widgets\Shipped\TopRoutes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Nothing Cairn does may break the host application
|--------------------------------------------------------------------------
|
| CLAUDE.md's hardest rule to keep, because it has to hold on every path
| rather than on the happy one. Every recording, counting, reporting and
| maintenance entry point is wrapped, and these tests take the storage away
| underneath each of them.
|
| An analytics package that can take a site offline is a worse problem than
| missing analytics.
|
*/

function breakStorage(): void
{
    foreach (Tables::all() as $table) {
        Schema::connection(Tables::connection())->dropIfExists($table);
    }
}

it('swallows a failure in every recording entry point', function (): void {
    breakStorage();

    expect(function (): void {
        $cairn = app(Cairn::class);

        $cairn->event('signed_up', ['plan' => 'pro']);
        $cairn->conversion('purchase', 49.99);
        $cairn->record(anEntry());
        $cairn->touch(request(), '/pricing');
        $cairn->digest();
        $cairn->live();
    })->not->toThrow(Throwable::class);
});

it('reports zero live visitors rather than failing', function (): void {
    breakStorage();

    expect(app(Cairn::class)->live())->toBe(0);
});

it('swallows a failure in counting and presence', function (): void {
    breakStorage();

    expect(function (): void {
        app(UniqueCounter::class)->add('2026-03-14', 'overall', random_bytes(16));
        app(UniqueCounter::class)->count('2026-03-14', 'overall');
        app(UniqueCounter::class)->prune('2026-03-14');
        app(Presence::class)->touch(random_bytes(16), '/pricing');
        app(Presence::class)->count();
        app(Presence::class)->recent();
    })->not->toThrow(Throwable::class);
});

it('swallows a failure in ingest and storage', function (): void {
    breakStorage();

    expect(function (): void {
        app(Ingest::class)->record(anEntry());
        app(Ingest::class)->digest(app(Storage::class));
        app(Ingest::class)->trim();
    })->not->toThrow(Throwable::class);
});

it('swallows a failure in pruning', function (): void {
    breakStorage();

    config()->set('cairn.retention.entries', 1);
    config()->set('cairn.retention.sessions', 1);
    config()->set('cairn.retention.aggregates', 1);

    expect(fn (): array => app(Pruner::class)->prune())->not->toThrow(Throwable::class);
});

it('reports no partitions when the schema is gone', function (): void {
    breakStorage();

    expect(app(Pruner::class)->isPartitioned(Tables::entries()))->toBeFalse()
        ->and(app(Pruner::class)->droppablePartitions(Tables::entries(), CarbonImmutable::now('UTC')))
        ->toBe([]);
});

it('swallows a failure in maintenance', function (): void {
    breakStorage();

    expect(fn (): bool => app(Maintenance::class)->run())->not->toThrow(Throwable::class);
});

/*
|--------------------------------------------------------------------------
| Maintenance
|--------------------------------------------------------------------------
*/

it('runs maintenance once and then holds a cooldown', function (): void {
    $maintenance = app(Maintenance::class);

    expect($maintenance->run())->toBeTrue()
        // A second run inside the cooldown must not repeat the work: on a busy
        // site that would mean paying for the same rollup over and over.
        ->and($maintenance->run())->toBeFalse();
});

it('never draws the lottery when it is switched off', function (): void {
    config()->set('cairn.ingest.lottery', [0, 100]);

    expect(app(Maintenance::class)->wins())->toBeFalse()
        ->and(app(Maintenance::class)->tick())->toBeFalse();
});

it('always draws the lottery when it is certain', function (): void {
    config()->set('cairn.ingest.lottery', [100, 100]);

    expect(app(Maintenance::class)->wins())->toBeTrue();
});

it('ignores a malformed lottery setting', function (mixed $lottery): void {
    config()->set('cairn.ingest.lottery', $lottery);

    expect(app(Maintenance::class)->wins())->toBeFalse();
})->with([
    'not an array' => ['nonsense'],
    'wrong length' => [[5]],
    'negative' => [[-1, 100]],
    'zero denominator' => [[1, 0]],
]);

/*
|--------------------------------------------------------------------------
| Widgets
|--------------------------------------------------------------------------
|
| One panel asking for something unavailable must not take the other fourteen
| down with it.
|
*/

it('returns empty rows rather than failing when a widget cannot query', function (): void {
    breakStorage();

    $filters = new Filters(range: 'today');

    expect(app(TopRoutes::class)->rows($filters))->toBeEmpty()
        ->and(app(ActivityFeed::class)->rows($filters))->toBeEmpty();
});

it('returns a zeroed overview rather than failing', function (): void {
    breakStorage();

    $filters = new Filters(range: 'today');

    $row = row(app(Overview::class)->rows($filters));

    expect($row->metrics)->toBe([])
        ->and(app(Overview::class)->series($filters))->toBeEmpty();
});

it('reports zero live visitors in the widget rather than failing', function (): void {
    breakStorage();

    $row = row(app(LiveVisitors::class)->rows(new Filters(range: 'today')));

    expect($row->metrics['live'] ?? null)->toBe(0.0);
});

/*
|--------------------------------------------------------------------------
| Data-subject commands
|--------------------------------------------------------------------------
*/

it('exports nothing for a subject with no activity', function (): void {
    $export = app(Eraser::class)->exportUser(999);

    expect($export['entries'])->toBe([])
        ->and($export['note'])->toContain('track_user_id');
});

it('reports nothing erased for a subject that was never recorded', function (): void {
    $removed = app(Eraser::class)->forgetVisitor(bin2hex(random_bytes(16)));

    expect($removed['entries'])->toBe(0)
        ->and($removed['sessions'])->toBe(0)
        ->and($removed['aggregates_rebuilt'])->toBe(0);
});

it('accepts a raw binary hash as well as hex', function (): void {
    $visitor = random_bytes(16);

    expect(app(Eraser::class)->exportVisitor($visitor)['subject'])
        ->toBe(['type' => 'visitor', 'hash' => bin2hex($visitor)]);
});

/*
|--------------------------------------------------------------------------
| The report builder
|--------------------------------------------------------------------------
*/

it('returns nothing rather than failing when only unstored metrics are asked for', function (): void {
    // Visitors comes from the counter, not the aggregate table, so a report
    // asking for it alone must not query storage for nothing.
    $row = app(Cairn::class)->report()
        ->lastDays(1)
        ->metrics(Metric::Visitors)
        ->total();

    expect($row->metric(Metric::Visitors))->toBe(0.0);
});

it('applies a filter given as a backed enum', function (): void {
    expect(fn () => app(Cairn::class)->report()
        ->lastDays(1)
        ->groupBy(Dimension::Channel)
        ->filter(Dimension::Channel, Channel::Organic)
        ->get())->not->toThrow(Throwable::class);
});

it('exports an empty report as an empty array', function (): void {
    expect(app(Cairn::class)->report()->lastDays(1)->groupBy(Dimension::Route)->toArray())->toBe([]);
});
