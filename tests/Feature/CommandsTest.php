<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Divoto\Cairn\Commands\WorkCommand;
use Divoto\Cairn\Contracts\Ingest;
use Divoto\Cairn\Contracts\Storage;
use Divoto\Cairn\Geo\MaxMindGeoResolver;
use Divoto\Cairn\Geo\NullGeoResolver;
use Divoto\Cairn\Maintenance\Doctor;
use Divoto\Cairn\Maintenance\Finding;
use Divoto\Cairn\Recorders\PageViews;
use Divoto\Cairn\Support\Tables;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;

uses(RefreshDatabase::class);

/**
 * The titles of everything the doctor reports, as one string.
 */
function findingTitles(): string
{
    return implode(' | ', array_map(
        static fn (Finding $finding): string => $finding->title,
        app(Doctor::class)->examine(),
    ));
}

/**
 * Silence the findings a default test environment would otherwise produce, so
 * each test below reports on the one condition it sets.
 */
function quietDoctor(): void
{
    config()->set('cairn.ingest.lottery', [0, 100]);
    config()->set('cache.default', 'array');
}

/*
|--------------------------------------------------------------------------
| cairn:work
|--------------------------------------------------------------------------
*/

it('explains that the worker is not needed on the database driver', function (): void {
    config()->set('cairn.driver', 'database');

    expect(Artisan::call('cairn:work'))->toBe(0)
        ->and(Artisan::output())->toContain('only needed on the redis driver');
});

it('drains the Redis queue and stops after the requested batches', function (): void {
    Redis::connection()->flushdb();

    config()->set('cairn.driver', 'redis');

    foreach ([Ingest::class, Storage::class] as $contract) {
        app()->forgetInstance($contract);
    }

    app(Ingest::class)->record(anEntry(route: 'pricing.index'));

    expect(Artisan::call('cairn:work', ['--max-batches' => 1, '--sleep' => 0]))->toBe(0);

    $stored = app(DatabaseManager::class)
        ->connection(Tables::connection())
        ->table(Tables::entries())
        ->count();

    expect($stored)->toBe(1)
        ->and(Artisan::output())->toContain('Stopped after storing 1 entries');
});

it('stops immediately when the queue is empty and sleep is zero', function (): void {
    Redis::connection()->flushdb();

    config()->set('cairn.driver', 'redis');
    app()->forgetInstance(Ingest::class);

    expect(Artisan::call('cairn:work', ['--sleep' => 0]))->toBe(0);
});

/**
 * A backlog drains at full speed; the worker only pauses once there is
 * nothing left to do, and only for as long as configured.
 */
it('sleeps when the queue is empty before checking it again', function (): void {
    Redis::connection()->flushdb();

    config()->set('cairn.driver', 'redis');
    app()->forgetInstance(Ingest::class);

    $start = microtime(true);

    // The first empty batch does not reach the batch limit, so it sleeps
    // before the second (also empty) batch stops the command.
    expect(Artisan::call('cairn:work', ['--max-batches' => 2, '--sleep' => 1]))->toBe(0);
    expect(microtime(true) - $start)->toBeGreaterThanOrEqual(1.0);
});

it('marks itself to stop when it receives a shutdown signal', function (): void {
    if (! function_exists('pcntl_signal') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('pcntl and posix are required for this test.');
    }

    $command = new WorkCommand;

    $listen = new ReflectionMethod($command, 'listenForShutdown');
    $listen->invoke($command);

    posix_kill(posix_getpid(), SIGTERM);
    pcntl_signal_dispatch();

    $shouldStop = new ReflectionProperty($command, 'shouldStop');

    expect($shouldStop->getValue($command))->toBeTrue();
});

/**
 * Flushing the queue would take Cairn's buffered entries with it, so the
 * worker says so at start-up rather than during an incident.
 */
it('warns when Cairn shares a Redis connection with the queue', function (): void {
    config()->set('cairn.driver', 'redis');
    config()->set('cairn.redis.connection', 'default');
    config()->set('queue.connections.redis.connection', 'default');

    app()->forgetInstance(Ingest::class);

    Artisan::call('cairn:work', ['--sleep' => 0]);

    expect(Artisan::output())->toContain('same Redis connection as your queue');
});

/*
|--------------------------------------------------------------------------
| cairn:export
|--------------------------------------------------------------------------
*/

it('writes an export to a file when given a path', function (): void {
    $path = sys_get_temp_dir().'/cairn-export-'.bin2hex(random_bytes(4)).'.json';

    expect(Artisan::call('cairn:export', ['subject' => bin2hex(random_bytes(16)), '--path' => $path]))->toBe(0)
        ->and(is_file($path))->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true);

    expect($decoded)->toBeArray();

    unlink($path);
});

it('exports a user when asked for one', function (): void {
    expect(Artisan::call('cairn:export', ['subject' => '42', '--user' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('track_user_id');
});

/*
|--------------------------------------------------------------------------
| cairn:forget
|--------------------------------------------------------------------------
*/

it('erases nothing when the confirmation is declined', function (): void {
    $visitor = random_bytes(16);

    app(DatabaseManager::class)->connection(Tables::connection())->table(Tables::entries())->insert([
        'occurred_at' => CarbonImmutable::now('UTC')->toDateTimeString(),
        'type' => 'pageview',
        'visitor' => binaryColumn($visitor),
        'tenant_id' => '',
    ]);

    $command = cairnTest()->artisan('cairn:forget', ['subject' => bin2hex($visitor)]);

    if (! $command instanceof PendingCommand) {
        throw new RuntimeException('artisan() did not return a PendingCommand.');
    }

    $command->expectsConfirmation(
        'Permanently erase all Cairn data for visitor "'.bin2hex($visitor).'"?',
        'no'
    )
        ->expectsOutputToContain('Nothing was erased')
        ->assertSuccessful();

    expect(app(DatabaseManager::class)->connection(Tables::connection())
        ->table(Tables::entries())->count())->toBe(1);
});

it('erases a user when asked for one', function (): void {
    expect(Artisan::call('cairn:forget', ['subject' => '42', '--user' => true, '--force' => true]))->toBe(0);

    // The rotation caveat applies to visitor hashes, not to user ids.
    expect(Artisan::output())->not->toContain('rotates every 24 hours');
});

/*
|--------------------------------------------------------------------------
| cairn:doctor
|--------------------------------------------------------------------------
*/

/**
 * The most consequential finding: anybody who finds the URL can read every
 * page, referrer and campaign on the site.
 */
it('reports a dashboard reachable without authentication in production', function (): void {
    quietDoctor();

    app()->detectEnvironment(fn (): string => 'production');
    Gate::define('viewCairn', fn (mixed $user = null): bool => true);

    expect(findingTitles())->toContain('reachable without authentication');
});

/**
 * Local is where the shipped gate permits everybody by design, so an open gate
 * there is not worth reporting. Note testbench runs as "testing", not "local",
 * which is why this has to be set explicitly.
 */
it('reports nothing about the gate in a local environment', function (): void {
    quietDoctor();

    app()->detectEnvironment(fn (): string => 'local');
    Gate::define('viewCairn', fn (mixed $user = null): bool => true);

    expect(findingTitles())->not->toContain('reachable without authentication');
});

it('reports a gate that denies in production', function (): void {
    quietDoctor();

    app()->detectEnvironment(fn (): string => 'production');
    Gate::define('viewCairn', fn (mixed $user = null): bool => false);

    expect(findingTitles())->not->toContain('reachable without authentication');
});

it('reports nothing about the gate when checking it fails', function (): void {
    quietDoctor();

    app()->detectEnvironment(fn (): string => 'production');
    Gate::define('viewCairn', function (mixed $user = null): bool {
        throw new RuntimeException('gate check failed');
    });

    expect(fn (): string => findingTitles())->not->toThrow(Throwable::class);
    expect(findingTitles())->not->toContain('reachable without authentication');
});

it('reports pageviews grouped by URL path', function (): void {
    quietDoctor();
    config()->set('cairn.recorders.'.PageViews::class.'.group_by', 'path');

    expect(findingTitles())->toContain('grouped by URL path');
});

it('reports raw entries kept forever', function (): void {
    quietDoctor();
    config()->set('cairn.retention.entries');

    expect(findingTitles())->toContain('kept forever');
});

it('reports a Redis connection shared with the queue', function (): void {
    quietDoctor();

    config()->set('cairn.driver', 'redis');
    config()->set('cairn.redis.connection', 'default');
    config()->set('queue.connections.redis.connection', 'default');

    expect(findingTitles())->toContain('shares a Redis connection');
});

it('reports nothing about Redis on the database driver', function (): void {
    quietDoctor();

    config()->set('cairn.driver', 'database');
    config()->set('queue.connections.redis.connection', 'default');

    expect(findingTitles())->not->toContain('shares a Redis connection');
});

it('reports nothing about Redis when the two connections genuinely differ', function (): void {
    quietDoctor();

    config()->set('cairn.driver', 'redis');
    config()->set('cairn.redis.connection', 'cairn');
    config()->set('queue.connections.redis.connection', 'default');

    expect(findingTitles())->not->toContain('shares a Redis connection');
});

it('reports maintenance running from the request lottery', function (): void {
    config()->set('cache.default', 'array');
    config()->set('cairn.ingest.lottery', [2, 100]);

    expect(findingTitles())->toContain('request lottery');
});

/**
 * The quiet failure: geo is configured, the file is not there, and the
 * Countries panel stays empty forever with nothing explaining why.
 */
it('reports a MaxMind database that cannot be read', function (): void {
    quietDoctor();

    config()->set('cairn.privacy.geo_resolver', MaxMindGeoResolver::class);
    config()->set('cairn.privacy.geo_database', '/nonexistent/GeoLite2-Country.mmdb');

    expect(findingTitles())->toContain('MaxMind database is configured but cannot be read');
});

it('reports nothing about geo when no database is configured at all', function (): void {
    quietDoctor();

    config()->set('cairn.privacy.geo_resolver', NullGeoResolver::class);

    expect(findingTitles())->not->toContain('MaxMind');
});

/**
 * Distinguishes two quiet failures with the same title: a path that was
 * never set, and a path that was set but points at nothing readable — the
 * message above names the setting, this one says there is nothing to name.
 */
it('explains that no path is configured at all, rather than naming a missing file', function (): void {
    quietDoctor();

    config()->set('cairn.privacy.geo_resolver', MaxMindGeoResolver::class);
    config()->set('cairn.privacy.geo_database');

    $finding = collect(app(Doctor::class)->examine())
        ->first(fn (Finding $f): bool => str_contains($f->title, 'MaxMind database'));

    if ($finding === null) {
        throw new RuntimeException('Expected a finding about the MaxMind database.');
    }

    expect($finding->implication)->toContain('privacy.geo_database is not set');
});

it('reports region-level geo as well as city', function (): void {
    quietDoctor();
    config()->set('cairn.privacy.geo_precision', 'region');

    expect(findingTitles())->toContain('region level');
});

it('ignores an unrecognised geo precision', function (): void {
    quietDoctor();
    config()->set('cairn.privacy.geo_precision', 'nonsense');

    expect(findingTitles())->not->toContain('level');
});

it('reports a file cache as well as a database one', function (): void {
    config()->set('cairn.ingest.lottery', [0, 100]);
    config()->set('cairn.cache_store', 'file');
    config()->set('cache.stores.file.driver', 'file');

    expect(findingTitles())->toContain('stored on disk');
});

it('reports nothing about the salt store when no cache store can be determined at all', function (): void {
    quietDoctor();
    config()->set('cairn.cache_store');
    config()->set('cache.default');

    expect(findingTitles())->not->toContain('stored on disk');
});

it('marks a severe finding as severe and an ordinary one as not', function (): void {
    quietDoctor();
    config()->set('cairn.enabled', false);

    $findings = app(Doctor::class)->examine();
    $severe = array_values(array_filter($findings, static fn (Finding $f): bool => $f->severe));

    expect($severe)->not->toBeEmpty()
        ->and($severe[0]->title)->toBe('Cairn is disabled');

    // An elevated geo precision is worth mentioning, not worth alarming about.
    config()->set('cairn.enabled', true);
    config()->set('cairn.privacy.geo_precision', 'city');

    $geo = array_values(array_filter(
        app(Doctor::class)->examine(),
        static fn (Finding $f): bool => str_contains($f->title, 'city level'),
    ));

    expect($geo)->not->toBeEmpty()
        ->and($geo[0]->severe)->toBeFalse();
});

it('lists the tables it reports on', function (): void {
    expect(app(Doctor::class)->tables())->toBe(Tables::all());
});

it('reports a missing table rather than failing', function (): void {
    Schema::connection(Tables::connection())->drop(Tables::entries());

    expect(Artisan::call('cairn:doctor'))->toBe(0)
        ->and(Artisan::output())->toContain('run migrate');
});
