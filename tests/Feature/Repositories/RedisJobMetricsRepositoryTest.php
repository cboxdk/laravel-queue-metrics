<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Repositories\RedisJobMetricsRepository;
use Cbox\LaravelQueueMetrics\Support\RedisMetricsStore;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

it('reads metric values instead of numeric job IDs and preserves chronological order', function (string $method, string $segment): void {
    config()->set('queue-metrics.storage.connection', 'default');
    config()->set('queue-metrics.storage.prefix', 'sample_test');
    $connection = Mockery::mock(Connection::class);
    Redis::shouldReceive('connection')->with('default')->once()->andReturn($connection);
    $connection->shouldReceive('zrange')->with("sample_test:{$segment}:redis:default:Job", -6, -1)->once()->andReturn([
        '110000000-abcd:80.25',
        '75628:96.5',
        'job:with:colon:0',
        'abcdef:1.5',
        '42.75',
        '9e7-uuid:1.25e2',
    ]);
    $repo = new RedisJobMetricsRepository(new RedisMetricsStore);

    expect($repo->{$method}('Job', 'redis', 'default', 6))->toBe([80.25, 96.5, 0.0, 1.5, 42.75, 125.0]);
})->with([
    ['getDurationSamples', 'durations'],
    ['getMemorySamples', 'memory'],
    ['getCpuTimeSamples', 'cpu'],
]);

it('round trips completed job samples through Redis', function (): void {
    if (! getenv('REDIS_AVAILABLE')) {
        $this->markTestSkipped('Requires isolated test Redis');
    }
    $prefix = 'sample_roundtrip_'.bin2hex(random_bytes(8));
    config()->set('queue-metrics.storage.connection', 'default');
    config()->set('queue-metrics.storage.prefix', $prefix);
    $store = new RedisMetricsStore;
    $repo = new RedisJobMetricsRepository($store);
    $now = Carbon\Carbon::now();
    foreach ([['110000000-abcd', 80.25], ['75628', 96.5]] as $index => [$id, $memory]) {
        $repo->recordCompletion($id, 'Job', 'redis', 'default', 150.0 + $index, $memory, 2.5 + $index, $now->copy()->addSeconds($index));
    }
    try {
        expect($repo->getMemorySamples('Job', 'redis', 'default'))->toBe([80.25, 96.5])
            ->and($repo->getDurationSamples('Job', 'redis', 'default'))->toBe([150.0, 151.0])
            ->and($repo->getCpuTimeSamples('Job', 'redis', 'default'))->toBe([2.5, 3.5])
            ->and($repo->getMemorySamples('Job', 'redis', 'default', 1))->toBe([96.5]);
    } finally {
        foreach (['memory', 'durations', 'cpu', 'jobs'] as $segment) {
            Redis::connection('default')->del($store->key($segment, 'redis', 'default', 'Job'));
        }
    }
})->group('redis');
