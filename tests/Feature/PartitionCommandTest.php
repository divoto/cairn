<?php

declare(strict_types=1);

use Divoto\Cairn\Support\Engine;
use Divoto\Cairn\Support\Tables;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/**
 * On an engine that cannot partition, the command must say so and succeed.
 *
 * Failing here would be worse than useless: partitioning is an optimisation,
 * and a non-zero exit would break a deployment script on the very hosts —
 * shared hosting, SQLite in CI — that Cairn promises to work on.
 */
it('no-ops with a clear message on an engine that cannot partition', function (): void {
    if (Engine::supportsPartitioning(Tables::driver())) {
        $this->markTestSkipped('This engine supports partitioning.');
    }

    $exit = Artisan::call('cairn:partition');

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('Partitioning is not available');
});

it('leaves the tables untouched when it cannot partition', function (): void {
    if (Engine::supportsPartitioning(Tables::driver())) {
        $this->markTestSkipped('This engine supports partitioning.');
    }

    expect(Artisan::call('cairn:partition'))->toBe(0);

    // The whole point of the no-op: everything still works afterwards.
    $connection = app(DatabaseManager::class)->connection(Tables::connection());

    $connection->table(Tables::entries())->insert([
        'occurred_at' => '2026-01-01 12:00:00',
        'type' => 'pageview',
        'visitor' => binaryColumn(random_bytes(16)),
    ]);

    expect($connection->table(Tables::entries())->count())->toBe(1);
});

it('is registered as a console command', function (): void {
    expect(array_keys(Artisan::all()))->toContain('cairn:partition');
});
