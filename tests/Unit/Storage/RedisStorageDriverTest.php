<?php

declare(strict_types=1);

use Illuminate\Redis\Connections\Connection;
use PHPeek\LaravelQueueMetrics\Storage\RedisStorageDriver;

beforeEach(function () {
    $this->connection = Mockery::mock(Connection::class);
    $this->driver = new RedisStorageDriver($this->connection);
});

it('sets and gets hash', function () {
    $this->connection->shouldReceive('hmset')
        ->once()
        ->with('test:key', ['field1' => 'value1', 'field2' => 'value2']);

    $this->connection->shouldReceive('expire')
        ->once()
        ->with('test:key', 3600);

    $this->driver->setHash('test:key', ['field1' => 'value1', 'field2' => 'value2'], 3600);

    $this->connection->shouldReceive('hgetall')
        ->once()
        ->with('test:key')
        ->andReturn(['field1' => 'value1', 'field2' => 'value2']);

    $result = $this->driver->getHash('test:key');

    expect($result)->toBe(['field1' => 'value1', 'field2' => 'value2']);
});

it('gets hash field', function () {
    $this->connection->shouldReceive('hget')
        ->once()
        ->with('test:key', 'field1')
        ->andReturn('value1');

    $result = $this->driver->getHashField('test:key', 'field1');

    expect($result)->toBe('value1');
});

it('increments hash field with integer', function () {
    $this->connection->shouldReceive('hincrby')
        ->once()
        ->with('test:key', 'counter', 5);

    $this->driver->incrementHashField('test:key', 'counter', 5);
});

it('increments hash field with float', function () {
    $this->connection->shouldReceive('hincrbyfloat')
        ->once()
        ->with('test:key', 'counter', 5.5);

    $this->driver->incrementHashField('test:key', 'counter', 5.5);
});

it('adds to sorted set with ttl', function () {
    $this->connection->shouldReceive('zadd')
        ->once()
        ->with('test:zset', ['member1' => 1.0, 'member2' => 2.0]);

    $this->connection->shouldReceive('expire')
        ->once()
        ->with('test:zset', 7200);

    $this->driver->addToSortedSet('test:zset', ['member1' => 1.0, 'member2' => 2.0], 7200);
});

it('gets sorted set by rank', function () {
    $this->connection->shouldReceive('zrange')
        ->once()
        ->with('test:zset', 0, 10)
        ->andReturn(['member1', 'member2', 'member3']);

    $result = $this->driver->getSortedSetByRank('test:zset', 0, 10);

    expect($result)->toBe(['member1', 'member2', 'member3']);
});

it('gets sorted set by score range', function () {
    $this->connection->shouldReceive('zrangebyscore')
        ->once()
        ->with('test:zset', '100', '200')
        ->andReturn(['member1', 'member2']);

    $result = $this->driver->getSortedSetByScore('test:zset', '100', '200');

    expect($result)->toBe(['member1', 'member2']);
});

it('counts sorted set by score range', function () {
    $this->connection->shouldReceive('zcount')
        ->once()
        ->with('test:zset', '100', '200')
        ->andReturn(5);

    $result = $this->driver->countSortedSetByScore('test:zset', '100', '200');

    expect($result)->toBe(5);
});

it('removes sorted set by rank', function () {
    $this->connection->shouldReceive('zremrangebyrank')
        ->once()
        ->with('test:zset', 0, 10)
        ->andReturn(3);

    $result = $this->driver->removeSortedSetByRank('test:zset', 0, 10);

    expect($result)->toBe(3);
});

it('adds to set', function () {
    $this->connection->shouldReceive('sadd')
        ->once()
        ->with('test:set', ['member1', 'member2', 'member3']);

    $this->driver->addToSet('test:set', ['member1', 'member2', 'member3']);
});

it('gets set members', function () {
    $this->connection->shouldReceive('smembers')
        ->once()
        ->with('test:set')
        ->andReturn(['member1', 'member2', 'member3']);

    $result = $this->driver->getSetMembers('test:set');

    expect($result)->toBe(['member1', 'member2', 'member3']);
});

it('removes from set', function () {
    $this->connection->shouldReceive('srem')
        ->once()
        ->with('test:set', ['member1', 'member2']);

    $this->driver->removeFromSet('test:set', ['member1', 'member2']);
});

it('removes from sorted set', function () {
    $this->connection->shouldReceive('zrem')
        ->once()
        ->with('test:zset', 'member1');

    $this->driver->removeFromSortedSet('test:zset', 'member1');
});

it('sets and gets simple value with ttl', function () {
    $this->connection->shouldReceive('setex')
        ->once()
        ->with('test:key', 1800, 'value');

    $this->driver->set('test:key', 'value', 1800);

    $this->connection->shouldReceive('get')
        ->once()
        ->with('test:key')
        ->andReturn('value');

    $result = $this->driver->get('test:key');

    expect($result)->toBe('value');
});

it('sets simple value without ttl', function () {
    $this->connection->shouldReceive('set')
        ->once()
        ->with('test:key', 'value');

    $this->driver->set('test:key', 'value');
});

it('deletes single key', function () {
    $this->connection->shouldReceive('del')
        ->once()
        ->with('test:key')
        ->andReturn(1);

    $result = $this->driver->delete('test:key');

    expect($result)->toBe(1);
});

it('deletes multiple keys', function () {
    $this->connection->shouldReceive('del')
        ->once()
        ->with('key1', 'key2', 'key3')
        ->andReturn(3);

    $result = $this->driver->delete(['key1', 'key2', 'key3']);

    expect($result)->toBe(3);
});

it('checks if key exists', function () {
    $this->connection->shouldReceive('exists')
        ->once()
        ->with('test:key')
        ->andReturn(1);

    $result = $this->driver->exists('test:key');

    expect($result)->toBeTrue();
});

it('returns false when key does not exist', function () {
    $this->connection->shouldReceive('exists')
        ->once()
        ->with('test:key')
        ->andReturn(0);

    $result = $this->driver->exists('test:key');

    expect($result)->toBeFalse();
});

it('sets expiration on key', function () {
    $this->connection->shouldReceive('expire')
        ->once()
        ->with('test:key', 3600)
        ->andReturn(1);

    $result = $this->driver->expire('test:key', 3600);

    expect($result)->toBeTrue();
});

it('scans keys by pattern', function () {
    // Mock scan() to return cursor '0' (done) with found keys
    $this->connection->shouldReceive('scan')
        ->once()
        ->with('0', ['match' => 'queue_metrics:*', 'count' => 100])
        ->andReturn(['0', ['queue_metrics:key1', 'queue_metrics:key2']]);

    $result = $this->driver->scanKeys('queue_metrics:*');

    expect($result)->toBe(['queue_metrics:key1', 'queue_metrics:key2']);
});

it('executes commands in pipeline', function () {
    $pipelineMock = Mockery::mock();

    $this->connection->shouldReceive('pipeline')
        ->once()
        ->andReturnUsing(function ($callback) use ($pipelineMock) {
            $callback($pipelineMock);
        });

    $executed = false;
    $this->driver->pipeline(function ($pipe) use (&$executed) {
        $executed = true;
    });

    expect($executed)->toBeTrue();
});

it('handles null return from get', function () {
    $this->connection->shouldReceive('get')
        ->once()
        ->with('nonexistent')
        ->andReturn(null);

    $result = $this->driver->get('nonexistent');

    expect($result)->toBeNull();
});
