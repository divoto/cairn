<?php

declare(strict_types=1);

use Divoto\Cairn\Contracts\Ingest;
use Divoto\Cairn\Ingest\RedisIngest;
use Illuminate\Support\Facades\Redis;

/*
|--------------------------------------------------------------------------
| RedisIngest
|--------------------------------------------------------------------------
|
| The happy path — record, digest, trim, and driver parity with the database
| ingest — is covered by DriverParityTest against a real Redis. What is here
| is specific to this driver: pending(), which nothing else calls, and each
| method's own failure handling.
|
*/

beforeEach(function (): void {
    try {
        Redis::connection()->flushdb();
    } catch (Throwable $e) {
        $this->markTestSkipped('Redis is required for this suite: '.$e->getMessage());
    }

    config()->set('cairn.driver', 'redis');
    app()->forgetInstance(Ingest::class);
});

it('reports how many entries are waiting to be drained', function (): void {
    $ingest = app(RedisIngest::class);

    expect($ingest->pending())->toBe(0);

    $ingest->record(anEntry());
    $ingest->record(anEntry());

    expect($ingest->pending())->toBe(2);
});

it('reports zero pending rather than failing when Redis is unreachable', function (): void {
    config()->set('cairn.redis.connection', 'this-connection-does-not-exist');

    expect(app(RedisIngest::class)->pending())->toBe(0);
});

it('swallows a failure recording an entry', function (): void {
    config()->set('cairn.redis.connection', 'this-connection-does-not-exist');

    expect(fn (): null => app(Ingest::class)->record(anEntry()))->not->toThrow(Throwable::class);
});

it('swallows a failure trimming the queue', function (): void {
    config()->set('cairn.redis.connection', 'this-connection-does-not-exist');

    expect(fn (): null => app(Ingest::class)->trim())->not->toThrow(Throwable::class);
});
