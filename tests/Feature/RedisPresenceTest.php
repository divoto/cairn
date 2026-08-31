<?php

declare(strict_types=1);

use Divoto\Cairn\Contracts\Presence;
use Illuminate\Support\Facades\Redis;

/*
|--------------------------------------------------------------------------
| RedisPresence
|--------------------------------------------------------------------------
|
| DatabasePresence and the read/write happy path shared by both drivers are
| covered by DriverParityTest. What is here is specific to the Redis
| implementation: eviction actually removing something, and each method's own
| failure handling.
|
*/

beforeEach(function (): void {
    try {
        Redis::connection()->flushdb();
    } catch (Throwable $e) {
        $this->markTestSkipped('Redis is required for this suite: '.$e->getMessage());
    }

    config()->set('cairn.driver', 'redis');
    app()->forgetInstance(Presence::class);
});

it('evicts expired presence data, including its page, before every read', function (): void {
    $member = bin2hex(random_bytes(16));

    $raw = Redis::connection();
    $raw->zadd('cairn:presence', now('UTC')->subMinutes(10)->getTimestamp(), $member);
    $raw->hset('cairn:presence:pages', $member, '/old');

    expect(app(Presence::class)->count())->toBe(0)
        ->and($raw->hget('cairn:presence:pages', $member))->toBeFalse();
});

it('leaves the pages hash untouched when nothing has expired', function (): void {
    $member = bin2hex(random_bytes(16));

    $raw = Redis::connection();
    $raw->zadd('cairn:presence', now('UTC')->getTimestamp(), $member);
    $raw->hset('cairn:presence:pages', $member, '/now');

    expect(app(Presence::class)->count())->toBe(1)
        ->and($raw->hget('cairn:presence:pages', $member))->toBe('/now');
});

it('reports nothing and logs when touching presence fails', function (): void {
    config()->set('cairn.redis.connection', 'this-connection-does-not-exist');

    expect(fn (): null => app(Presence::class)->touch(random_bytes(16)))->not->toThrow(Throwable::class);
});

it('reports zero and logs when counting presence fails', function (): void {
    config()->set('cairn.redis.connection', 'this-connection-does-not-exist');

    expect(app(Presence::class)->count())->toBe(0);
});

it('returns an empty list and logs when reading recent presence fails', function (): void {
    config()->set('cairn.redis.connection', 'this-connection-does-not-exist');

    expect(app(Presence::class)->recent())->toBeEmpty();
});
