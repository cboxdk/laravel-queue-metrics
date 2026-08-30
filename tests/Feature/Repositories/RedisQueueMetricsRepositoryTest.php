<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\Contracts\QueueInspector;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueDepthData;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;
use Cbox\LaravelQueueMetrics\Repositories\RedisQueueMetricsRepository;
use Cbox\LaravelQueueMetrics\Support\HealthScoreCalculator;
use Cbox\LaravelQueueMetrics\Support\RedisMetricsStore;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    if (! getenv('REDIS_AVAILABLE')) {
        test()->markTestSkipped('Requires Redis - run with redis group');
    }

    config()->set('queue-metrics.enabled', true);
    config()->set('queue-metrics.storage.driver', 'redis');
    config()->set('queue-metrics.storage.connection', 'default');

    Redis::connection('default')->flushdb();

    $this->inspector = Mockery::mock(QueueInspector::class);
    $this->workers = Mockery::mock(WorkerHeartbeatRepository::class);
    $this->repo = new RedisQueueMetricsRepository(app(RedisMetricsStore::class), $this->inspector, $this->workers, new HealthScoreCalculator);
});

test('getQueueState returns live depth info from the queue inspector', function () {
    $this->inspector->shouldReceive('getQueueDepth')
        ->with('redis', 'default')
        ->andReturn(new QueueDepthData(
            connection: 'redis',
            queue: 'default',
            pendingJobs: 42,
            reservedJobs: 3,
            delayedJobs: 5,
            oldestPendingJobAge: Carbon::now()->subSeconds(120),
            oldestDelayedJobAge: null,
            measuredAt: Carbon::now(),
        ));

    $state = $this->repo->getQueueState('redis', 'default');

    expect($state['depth'])->toBe(50)
        ->and($state['pending'])->toBe(42)
        ->and($state['scheduled'])->toBe(5)
        ->and($state['reserved'])->toBe(3)
        ->and($state['oldest_job_age'])->toBe(120);
})->group('redis');

test('recordSnapshot stores snapshot and getLatestMetrics retrieves stored fields', function () {
    $this->repo->recordSnapshot('redis', 'default', [
        'depth' => 10,
        'pending' => 8,
        'scheduled' => 1,
        'reserved' => 1,
        'oldest_job_age' => 120,
        'throughput_per_minute' => 5.5,
        'avg_duration' => 250.0,
        'failure_rate' => 2.0,
        'utilization_rate' => 0.75,
        'active_workers' => 3,
    ]);

    $latest = $this->repo->getLatestMetrics('redis', 'default');

    expect($latest['depth'])->toBe(10)
        ->and($latest['pending'])->toBe(8)
        ->and($latest['scheduled'])->toBe(1)
        ->and($latest['reserved'])->toBe(1)
        ->and($latest['oldest_job_age'])->toBe(120)
        ->and($latest['throughput_per_minute'])->toBe(5.5)
        ->and($latest['avg_duration'])->toBe(250.0)
        ->and($latest['failure_rate'])->toBe(2.0)
        ->and($latest['utilization_rate'])->toBe(0.75)
        ->and($latest['active_workers'])->toBe(3)
        ->and($latest['recorded_at'])->not->toBeNull();
})->group('redis');

test('getLatestMetrics omits fields the snapshot does not store', function () {
    $this->repo->recordSnapshot('redis', 'default', [
        'throughput_per_minute' => 5.5,
        'avg_duration' => 250.0,
        'failure_rate' => 2.0,
    ]);

    $latest = $this->repo->getLatestMetrics('redis', 'default');

    expect($latest['throughput_per_minute'])->toBe(5.5)
        ->and($latest['avg_duration'])->toBe(250.0)
        ->and($latest['failure_rate'])->toBe(2.0)
        ->and($latest)->not->toHaveKeys(['depth', 'pending', 'scheduled', 'reserved', 'oldest_job_age', 'active_workers', 'utilization_rate']);
})->group('redis');

test('getLatestMetrics returns empty array when no snapshot exists', function () {
    expect($this->repo->getLatestMetrics('redis', 'default'))->toBe([]);
})->group('redis');

test('getHealthStatus scores real backlog even when the snapshot only stores performance fields', function () {
    Event::fake();

    $this->inspector->shouldReceive('getQueueDepth')->with('redis', 'default')->andReturn(new QueueDepthData(
        connection: 'redis',
        queue: 'default',
        pendingJobs: 152361,
        reservedJobs: 0,
        delayedJobs: 0,
        oldestPendingJobAge: Carbon::now()->subSeconds(83880),
        oldestDelayedJobAge: null,
        measuredAt: Carbon::now(),
    ));
    $this->workers->shouldReceive('getActiveWorkers')->with('redis', 'default')->andReturn(collect());

    $this->repo->recordSnapshot('redis', 'default', [
        'throughput_per_minute' => 10.0,
        'avg_duration' => 100.0,
        'failure_rate' => 0.0,
    ]);

    $status = $this->repo->getHealthStatus('redis', 'default');

    expect($status['status'])->toBe('critical')
        ->and($status['score'])->toBeLessThan(50.0);
})->group('redis');
