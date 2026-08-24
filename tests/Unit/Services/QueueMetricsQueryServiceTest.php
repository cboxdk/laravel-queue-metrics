<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;
use Cbox\LaravelQueueMetrics\Services\QueueMetricsQueryService;

/**
 * Resolve the service with the repository contract mocked, so the merge
 * behavior of getQueueMetrics() can be pinned without any storage backend.
 */
function queueMetricsServiceWith(array $state, array $latestMetrics): QueueMetricsQueryService
{
    $repository = Mockery::mock(QueueMetricsRepository::class);
    $repository->shouldReceive('getQueueState')->with('redis', 'default')->andReturn($state);
    $repository->shouldReceive('getLatestMetrics')->with('redis', 'default')->andReturn($latestMetrics);
    $repository->shouldReceive('getHealthStatus')->with('redis', 'default')->andReturn([
        'status' => 'warning',
        'score' => 60.0,
    ]);

    app()->instance(QueueMetricsRepository::class, $repository);

    return app(QueueMetricsQueryService::class);
}

test('getQueueMetrics reports live queue state alongside snapshot performance data', function () {
    $service = queueMetricsServiceWith(
        state: [
            'depth' => 152361,
            'pending' => 152361,
            'scheduled' => 0,
            'reserved' => 0,
            'oldest_job_age' => 83880,
        ],
        latestMetrics: [
            'throughput_per_minute' => 12.5,
            'avg_duration' => 0.3,
            'failure_rate' => 1.0,
            'recorded_at' => Carbon::now(),
        ],
    );

    $metrics = $service->getQueueMetrics('redis', 'default');

    expect($metrics->pending)->toBe(152361)
        ->and($metrics->oldestJobAge)->toBe(83880)
        ->and($metrics->throughputPerMinute)->toBe(12.5)
        ->and($metrics->failureRate)->toBe(1.0);
});

test('getQueueMetrics lets live state win when a snapshot carries stale state fields', function () {
    $service = queueMetricsServiceWith(
        state: [
            'depth' => 152361,
            'pending' => 152361,
            'scheduled' => 0,
            'reserved' => 0,
            'oldest_job_age' => 83880,
        ],
        latestMetrics: [
            'pending' => 0,
            'oldest_job_age' => 0,
            'throughput_per_minute' => 12.5,
        ],
    );

    $metrics = $service->getQueueMetrics('redis', 'default');

    expect($metrics->pending)->toBe(152361)
        ->and($metrics->oldestJobAge)->toBe(83880)
        ->and($metrics->throughputPerMinute)->toBe(12.5);
});

test('getQueueMetrics falls back to live state alone when no snapshot exists', function () {
    $service = queueMetricsServiceWith(
        state: [
            'depth' => 7,
            'pending' => 5,
            'scheduled' => 1,
            'reserved' => 1,
            'oldest_job_age' => 30,
        ],
        latestMetrics: [],
    );

    $metrics = $service->getQueueMetrics('redis', 'default');

    expect($metrics->pending)->toBe(5)
        ->and($metrics->oldestJobAge)->toBe(30)
        ->and($metrics->throughputPerMinute)->toBe(0.0);
});
