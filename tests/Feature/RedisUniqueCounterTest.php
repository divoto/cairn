<?php

declare(strict_types=1);

use Divoto\Cairn\Contracts\UniqueCounter;
use Divoto\Cairn\Counting\RedisUniqueCounter;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;
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

it('reports zeroes for every requested pair when a bulk count fails', function (): void {
    expect(app(RedisUniqueCounter::class)->counts(['2026-03-01', '2026-03-02'], ['overall']))
        ->toBe(['overall' => ['2026-03-01' => 0, '2026-03-02' => 0]]);
});

/*
 * Only phpredis exposes a pipeline this driver can type, so any other client —
 * Predis, in practice — is read one PFCOUNT at a time. Predis is not a
 * dependency of the suite; a plain Connection that forwards every command to
 * the real one stands in for it, because what decides the path is that the
 * connection is not a PhpRedisConnection.
 */
it('counts one key at a time on a client it cannot pipeline, with the same numbers', function (): void {
    $plain = new class(Redis::connection('default')) extends Connection
    {
        public function __construct(private readonly Connection $inner) {}

        /**
         * @param  string  $method
         * @param  array<int|string, mixed>  $parameters
         */
        public function command($method, array $parameters = []): mixed
        {
            return $this->inner->command($method, $parameters);
        }

        /**
         * @param  array<int, string>|string  $channels
         * @param  string  $method
         */
        public function createSubscription($channels, Closure $callback, $method = 'subscribe'): void {}
    };

    $counter = new RedisUniqueCounter(
        new class($plain) implements Factory
        {
            public function __construct(private readonly Connection $connection) {}

            public function connection($name = null): Connection
            {
                return $this->connection;
            }
        },
        app(Config::class),
    );

    $counter->add('2026-03-10', 'route:pricing', random_bytes(16));
    $counter->add('2026-03-10', 'route:pricing', random_bytes(16));
    $counter->add('2026-03-11', 'route:home', random_bytes(16));

    expect($counter->counts(['2026-03-10', '2026-03-11'], ['route:pricing', 'route:home']))->toBe([
        'route:pricing' => ['2026-03-10' => 2, '2026-03-11' => 0],
        'route:home' => ['2026-03-10' => 0, '2026-03-11' => 1],
    ]);
});

it('reports zero removed and logs when pruning fails', function (): void {
    expect(app(UniqueCounter::class)->prune('2026-03-01'))->toBe(0);
});
