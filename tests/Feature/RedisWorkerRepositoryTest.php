<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\Repositories\RedisWorkerRepository;
use Cbox\LaravelQueueMetrics\Support\RedisMetricsStore;
use Illuminate\Support\Facades\Redis;

/**
 * The stale-worker index (`workers:all`) is a sorted set scored by heartbeat
 * timestamp. cleanupStaleWorkers trims it server-side; these tests seed the
 * index the way the heartbeat path does and assert the trim, since materialising
 * the whole stale set is exactly what OOM-killed the command on a churning fleet.
 */
beforeEach(function () {
    if (! getenv('REDIS_AVAILABLE')) {
        test()->markTestSkipped('Requires Redis - run with redis group');
    }

    config()->set('queue-metrics.enabled', true);
    config()->set('queue-metrics.storage.driver', 'redis');
    config()->set('queue-metrics.storage.connection', 'default');

    Redis::connection('default')->flushdb();

    $this->store = app(RedisMetricsStore::class);
    $this->repository = new RedisWorkerRepository($this->store);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('cleanupStaleWorkers trims only the stale index members and keeps fresh ones', function () {
    $driver = $this->store->driver();
    $indexKey = $this->store->key('workers', 'all');

    $driver->addToSortedSet($indexKey, [
        'stale-1' => now()->subMinutes(5)->timestamp,
        'stale-2' => now()->subMinutes(2)->timestamp,
        'fresh-1' => now()->timestamp,
    ]);

    $removed = $this->repository->cleanupStaleWorkers(60);

    expect($removed)->toBe(2)
        ->and($driver->getSortedSetByRank($indexKey, 0, -1))->toBe(['fresh-1']);
})->group('redis');

test('cleanupStaleWorkers returns zero when no index member is stale', function () {
    $driver = $this->store->driver();
    $indexKey = $this->store->key('workers', 'all');

    $driver->addToSortedSet($indexKey, ['fresh-1' => now()->timestamp]);

    expect($this->repository->cleanupStaleWorkers(60))->toBe(0);
})->group('redis');
