<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Models\MetricsSet;
use Cbox\LaravelQueueMetrics\Support\DatabaseMetricsStore;

beforeEach(function () {
    // Models read queue-metrics.storage.connection for DB connection name.
    // Set to null so models fall back to Laravel's default connection ('testing').
    config()->set('queue-metrics.storage.connection', null);

    // Run the package migration (not auto-loaded since runsMigrations is not called).
    $createTables = include __DIR__.'/../../../database/migrations/2024_01_01_000001_create_queue_metrics_storage_tables.php';
    $createTables->up();

    $addSetExpiry = include __DIR__.'/../../../database/migrations/2024_01_01_000002_add_expires_at_to_queue_metrics_sets.php';
    $addSetExpiry->up();

    $this->store = new DatabaseMetricsStore;
});

// --- String operations ---

test('set and get a string value', function () {
    $this->store->set('test:key', 'hello');
    expect($this->store->get('test:key'))->toBe('hello');
});

test('set with TTL expires key', function () {
    $this->store->set('test:ttl', 'value', 1);
    expect($this->store->get('test:ttl'))->toBe('value');

    $this->travel(2)->seconds();
    expect($this->store->get('test:ttl'))->toBeNull();
});

test('exists returns true for existing key', function () {
    $this->store->set('test:exists', 'yes');
    expect($this->store->exists('test:exists'))->toBeTrue();
    expect($this->store->exists('test:nope'))->toBeFalse();
});

test('delete removes key', function () {
    $this->store->set('test:del', 'value');
    $this->store->delete('test:del');
    expect($this->store->get('test:del'))->toBeNull();
});

// --- Hash operations ---

test('setHash and getHash', function () {
    $this->store->setHash('test:hash', ['name' => 'Alice', 'age' => '30']);
    expect($this->store->getHash('test:hash'))->toBe(['name' => 'Alice', 'age' => '30']);
});

test('getHashField returns single field', function () {
    $this->store->setHash('test:hash', ['a' => '1', 'b' => '2']);
    expect($this->store->getHashField('test:hash', 'a'))->toBe('1');
});

test('incrementHashField increments integer', function () {
    $this->store->setHash('test:inc', ['count' => '5']);
    $this->store->incrementHashField('test:inc', 'count', 3);
    expect($this->store->getHashField('test:inc', 'count'))->toBe(8);
});

test('incrementHashField increments float', function () {
    $this->store->setHash('test:incf', ['total' => '1.5']);
    $this->store->incrementHashField('test:incf', 'total', 2.5);
    // JSON serialization may convert 4.0 to int 4, so check numeric equality.
    expect($this->store->getHashField('test:incf', 'total'))->toEqual(4.0);
});

test('incrementHashField creates field if missing', function () {
    $this->store->setHash('test:inc-new', ['other' => 'x']);
    $this->store->incrementHashField('test:inc-new', 'count', 1);
    expect($this->store->getHashField('test:inc-new', 'count'))->toBe(1);
});

// --- Sorted set operations ---

test('addToSortedSet and getSortedSetByScore', function () {
    $this->store->addToSortedSet('test:zset', ['alice' => 10, 'bob' => 20, 'carol' => 15]);
    $result = $this->store->getSortedSetByScore('test:zset', '0', '100');
    expect($result)->toBe(['alice', 'carol', 'bob']);
});

test('getSortedSetByRank returns by position', function () {
    $this->store->addToSortedSet('test:zset', ['a' => 1, 'b' => 2, 'c' => 3]);
    expect($this->store->getSortedSetByRank('test:zset', 0, 1))->toBe(['a', 'b']);
});

test('getSortedSetByRank with negative indices', function () {
    $this->store->addToSortedSet('test:zset', ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]);
    expect($this->store->getSortedSetByRank('test:zset', 0, -1))->toBe(['a', 'b', 'c', 'd']);
    expect($this->store->getSortedSetByRank('test:zset', -2, -1))->toBe(['c', 'd']);
});

test('countSortedSetByScore counts within range', function () {
    $this->store->addToSortedSet('test:zset', ['a' => 10, 'b' => 20, 'c' => 30]);
    expect($this->store->countSortedSetByScore('test:zset', '15', '25'))->toBe(1);
});

test('removeSortedSetByScore removes matching entries', function () {
    $this->store->addToSortedSet('test:zset', ['a' => 10, 'b' => 20, 'c' => 30]);
    $this->store->removeSortedSetByScore('test:zset', '0', '15');
    expect($this->store->getSortedSetByScore('test:zset', '0', '100'))->toBe(['b', 'c']);
});

test('removeSortedSetByRank removes by position', function () {
    $this->store->addToSortedSet('test:zset', ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]);
    $this->store->removeSortedSetByRank('test:zset', 0, 1);
    expect($this->store->getSortedSetByScore('test:zset', '0', '100'))->toBe(['c', 'd']);
});

test('removeFromSortedSet removes specific member', function () {
    $this->store->addToSortedSet('test:zset', ['a' => 1, 'b' => 2]);
    $this->store->removeFromSortedSet('test:zset', 'a');
    expect($this->store->getSortedSetByScore('test:zset', '0', '100'))->toBe(['b']);
});

// --- Set operations ---

test('addToSet and getSetMembers', function () {
    $this->store->addToSet('test:set', ['x', 'y', 'z']);
    $members = $this->store->getSetMembers('test:set');
    sort($members);
    expect($members)->toBe(['x', 'y', 'z']);
});

test('addToSet ignores duplicates', function () {
    $this->store->addToSet('test:set', ['x', 'y']);
    $this->store->addToSet('test:set', ['y', 'z']);
    expect($this->store->getSetMembers('test:set'))->toHaveCount(3);
});

test('removeFromSet removes members', function () {
    $this->store->addToSet('test:set', ['a', 'b', 'c']);
    $this->store->removeFromSet('test:set', ['b']);
    $members = $this->store->getSetMembers('test:set');
    sort($members);
    expect($members)->toBe(['a', 'c']);
});

test('getSetMembers hides legacy transient rows after their inferred TTL', function () {
    config()->set('queue-metrics.storage.ttl.raw', 60);

    MetricsSet::create([
        'key' => 'active_workers',
        'member' => 'host:1',
        'created_at' => now()->subMinutes(5),
        'expires_at' => null,
    ]);

    expect($this->store->getSetMembers('active_workers'))->toBe([]);
});

test('getSetMembers keeps legacy permanent rows without expiry', function () {
    MetricsSet::create([
        'key' => 'test:set',
        'member' => 'permanent',
        'created_at' => now()->subYear(),
        'expires_at' => null,
    ]);

    expect($this->store->getSetMembers('test:set'))->toBe(['permanent']);
});

// --- Key scanning ---

test('scanKeys finds matching keys', function () {
    $this->store->set('prefix:a', '1');
    $this->store->set('prefix:b', '2');
    $this->store->set('other:c', '3');
    $keys = $this->store->scanKeys('prefix:*');
    sort($keys);
    expect($keys)->toBe(['prefix:a', 'prefix:b']);
});

// --- Transaction ---

test('transaction wraps operations atomically', function () {
    $this->store->transaction(function () {
        $this->store->set('tx:a', '1');
        $this->store->set('tx:b', '2');
    });
    expect($this->store->get('tx:a'))->toBe('1');
    expect($this->store->get('tx:b'))->toBe('2');
});
