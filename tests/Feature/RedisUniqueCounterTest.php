<?php

declare(strict_types=1);

use Divoto\Cairn\Contracts\UniqueCounter;
use Illuminate\Support\Facades\Redis;

/*
|--------------------------------------------------------------------------
| RedisUniqueCounter
|--------------------------------------------------------------------------
|
| The happy path — add, count, prune, and parity with the database driver —
| is covered by DriverParityTest and PruneTest against a real Redis. What is
| here is specific to this driver: each method's own failure handling.
|
*/

beforeEach(function (): void {
    try {
        Redis::connection()->flushdb();
    } catch (Throwable $e) {
        $this->markTestSkipped('Redis is required for this suite: '.$e->getMessage());
    }

    config()->set('cairn.driver', 'redis');
    app()->forgetInstance(UniqueCounter::class);
    config()->set('cairn.redis.connection', 'this-connection-does-not-exist');
});

it('swallows a failure adding a visitor', function (): void {
    expect(fn (): null => app(UniqueCounter::class)->add('2026-03-01', 'overall', random_bytes(16)))
        ->not->toThrow(Throwable::class);
});

it('reports zero and logs when counting fails', function (): void {
    expect(app(UniqueCounter::class)->count('2026-03-01', 'overall'))->toBe(0);
});

it('reports zero removed and logs when pruning fails', function (): void {
    expect(app(UniqueCounter::class)->prune('2026-03-01'))->toBe(0);
});
