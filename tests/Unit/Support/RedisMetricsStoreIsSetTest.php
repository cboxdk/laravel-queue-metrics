<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Support\RedisMetricsStore;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * The TYPE reply differs per client: phpredis returns an integer constant,
 * predis a status object that casts to the type name. Both must be read
 * without a live server, so the connection is mocked here; the redis group
 * covers a real server in RedisDiscoveryAgeingTest.
 */
function isSetStoreReturningType(mixed $typeReply): RedisMetricsStore
{
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('command')->with('type', ['some:key'])->andReturn($typeReply);

    Redis::shouldReceive('connection')->andReturn($connection);

    return new RedisMetricsStore;
}

it('reads a phpredis integer type reply', function (int $reply, bool $expected) {
    expect(isSetStoreReturningType($reply)->isSet('some:key'))->toBe($expected);
})->with([
    'set' => [\Redis::REDIS_SET, true],
    'sorted set' => [\Redis::REDIS_ZSET, false],
    'string' => [\Redis::REDIS_STRING, false],
    'hash' => [\Redis::REDIS_HASH, false],
    'missing key' => [\Redis::REDIS_NOT_FOUND, false],
]);

it('reads a predis status type reply', function (string $reply, bool $expected) {
    $status = new class($reply) implements Stringable
    {
        public function __construct(private string $type) {}

        public function __toString(): string
        {
            return $this->type;
        }
    };

    expect(isSetStoreReturningType($status)->isSet('some:key'))->toBe($expected);
})->with([
    'set' => ['set', true],
    'sorted set' => ['zset', false],
    'missing key' => ['none', false],
]);

it('reads a plain string type reply', function (string $reply, bool $expected) {
    expect(isSetStoreReturningType($reply)->isSet('some:key'))->toBe($expected);
})->with([
    'set' => ['set', true],
    'sorted set' => ['zset', false],
]);

it('treats an unexpected type reply as not a set', function (mixed $reply) {
    expect(isSetStoreReturningType($reply)->isSet('some:key'))->toBeFalse();
})->with([
    'false' => [false],
    'null' => [null],
]);
