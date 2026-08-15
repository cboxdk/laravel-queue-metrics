<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueDepthData;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use Cbox\LaravelQueueMetrics\Services\LaravelQueueInspector;
use Cbox\LaravelQueueMetrics\Support\RedisMetricsStore;
use Illuminate\Support\Facades\Redis;

/**
 * Redis Cluster compatibility regression tests
 *
 * These run against whichever Redis the environment provides: a single node normally, or a
 * real cluster when REDIS_CLUSTER_HOSTS_AND_PORTS is set (see the cluster CI job). Assertions
 * hold in both modes; the cluster run is the one that fails before the fixes land.
 */
beforeEach(function () {
    if (! getenv('REDIS_AVAILABLE')) {
        $this->markTestSkipped('Requires Redis - run with redis group');
    }

    config()->set('queue-metrics.enabled', true);
    config()->set('queue-metrics.storage.driver', 'redis');
    config()->set('queue-metrics.storage.connection', 'default');

    config()->set('queue.connections.redis', [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => 'default',
        'retry_after' => 90,
    ]);

    Redis::connection('default')->flushdb();
});

// Issue A — scanKeys must not throw and must return written keys on a cluster.
it('scans keys across the keyspace', function () {
    $store = app(RedisMetricsStore::class);

    $store->set('queue_metrics:scan:a', '1');
    $store->set('queue_metrics:scan:b', '1');

    $keys = $store->scanKeys('queue_metrics:scan:*');

    expect($keys)->toHaveCount(2);
})->group('redis');

// Issue B — depth reads the {hash tag} key Laravel's RedisQueue writes on a cluster.
it('reads queue depth for an unbraced queue name', function () {
    $queue = app('queue')->connection('redis');
    $queue->pushRaw('{"job":"a"}', 'depthtest');
    $queue->pushRaw('{"job":"b"}', 'depthtest');
    $queue->pushRaw('{"job":"c"}', 'depthtest');

    $depth = invokeRedisQueueDepth($queue, 'depthtest');

    expect($depth->pendingJobs)->toBe(3);
})->group('redis');

// Issue B — an already-braced queue name must not be double-wrapped.
it('reads queue depth for an already-braced queue name without double wrapping', function () {
    $queue = app('queue')->connection('redis');
    $queue->pushRaw('{"job":"a"}', '{braced}');
    $queue->pushRaw('{"job":"b"}', '{braced}');

    $depth = invokeRedisQueueDepth($queue, '{braced}');

    expect($depth->pendingJobs)->toBe(2);
})->group('redis');

// Issue C — transaction() writes succeed and are readable on a cluster.
it('writes through a transaction and reads the result back', function () {
    $repository = app(JobMetricsRepository::class);
    $store = app(RedisMetricsStore::class);

    $repository->recordStart('cluster-job-1', 'App\\Jobs\\Example', 'redis', 'default', now());

    $metricsKey = $store->key('jobs', 'redis', 'default', 'App\\Jobs\\Example');

    expect($store->getHash($metricsKey)['total_queued'])->toBe('1');
})->group('redis');

// Issue C — pipeline() writes succeed and are readable on a cluster.
it('writes through a pipeline and reads the result back', function () {
    $store = app(RedisMetricsStore::class);
    $key = $store->key('pipeline', 'example');

    $store->pipeline(function ($pipe) use ($key) {
        $pipe->setHash($key, ['field' => 'value']);
    });

    expect($store->getHash($key))->toMatchArray(['field' => 'value']);
})->group('redis');

/**
 * Invoke the reflection-based depth path directly so Issue B is covered even on Laravel
 * versions whose RedisQueue exposes native size methods (which bypass getRedisQueueDepth).
 */
function invokeRedisQueueDepth(object $queue, string $queueName): QueueDepthData
{
    $inspector = new LaravelQueueInspector(app('queue'));

    $method = (new ReflectionClass($inspector))->getMethod('getRedisQueueDepth');
    $method->setAccessible(true);

    /** @var QueueDepthData $depth */
    $depth = $method->invoke($inspector, $queue, 'redis', $queueName);

    return $depth;
}
