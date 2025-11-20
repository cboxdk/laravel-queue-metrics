<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use PHPeek\LaravelQueueMetrics\Actions\CalculateQueueMetricsAction;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;

beforeEach(function () {
    // Skip tests if Redis is not available
    if (! getenv('REDIS_AVAILABLE')) {
        test()->markTestSkipped('Requires Redis - run with redis group');
    }

    config()->set('queue-metrics.enabled', true);
    config()->set('queue-metrics.storage.driver', 'redis');
    config()->set('queue-metrics.storage.connection', 'default');
});

it('calculates queue metrics from job metrics', function () {
    $connection = 'redis';
    $queue = 'default';
    $jobClass1 = 'App\\Jobs\\ProcessOrder';
    $jobClass2 = 'App\\Jobs\\SendEmail';

    // Setup - Record job metrics for two different job classes
    $jobRepo = app(JobMetricsRepository::class);
    $queueRepo = app(QueueMetricsRepository::class);

    // Mark jobs as discovered
    $jobRepo->markJobDiscovered($jobClass1, $connection, $queue);
    $jobRepo->markJobDiscovered($jobClass2, $connection, $queue);

    // Mark queue as discovered
    $queueRepo->markQueueDiscovered($connection, $queue);

    // Record job completions for job 1: 10 jobs, 100ms avg duration
    for ($i = 0; $i < 10; $i++) {
        $jobRepo->recordCompletion(
            jobClass: $jobClass1,
            connection: $connection,
            queue: $queue,
            durationMs: 100.0,
            memoryMb: 10.0,
            cpuTimeMs: 50.0,
        );
    }

    // Record job completions for job 2: 5 jobs, 200ms avg duration
    for ($i = 0; $i < 5; $i++) {
        $jobRepo->recordCompletion(
            jobClass: $jobClass2,
            connection: $connection,
            queue: $queue,
            durationMs: 200.0,
            memoryMb: 15.0,
            cpuTimeMs: 75.0,
        );
    }

    // Execute - Calculate queue metrics
    $action = app(CalculateQueueMetricsAction::class);
    $action->execute($connection, $queue);

    // Assert - Verify aggregated metrics
    $metrics = $queueRepo->getLatestMetrics($connection, $queue);

    expect($metrics)->not()->toBeEmpty('Queue metrics should not be empty');

    // Weighted average duration: (10 * 100 + 5 * 200) / 15 = 133.33ms
    $expectedAvgDuration = (10 * 100.0 + 5 * 200.0) / 15;
    expect($metrics['avg_duration'])->toBe($expectedAvgDuration);

    // Throughput should be sum of both job classes
    expect($metrics['throughput_per_minute'])->toBeGreaterThan(0.0);

    // Failure rate should be 0 (no failures recorded)
    expect($metrics['failure_rate'])->toBe(0.0);
})->group('redis');

it('handles queue with no jobs', function () {
    $connection = 'redis';
    $queue = 'empty';

    $queueRepo = app(QueueMetricsRepository::class);
    $queueRepo->markQueueDiscovered($connection, $queue);

    // Execute - Calculate metrics for empty queue
    $action = app(CalculateQueueMetricsAction::class);
    $action->execute($connection, $queue);

    // Assert - Should record zero metrics
    $metrics = $queueRepo->getLatestMetrics($connection, $queue);

    expect($metrics)->not()->toBeEmpty();
    expect($metrics['throughput_per_minute'])->toBe(0.0);
    expect($metrics['avg_duration'])->toBe(0.0);
    expect($metrics['failure_rate'])->toBe(0.0);
})->group('redis');

it('calculates failure rate correctly', function () {
    $connection = 'redis';
    $queue = 'default';
    $jobClass = 'App\\Jobs\\RiskyJob';

    $jobRepo = app(JobMetricsRepository::class);
    $queueRepo = app(QueueMetricsRepository::class);

    $jobRepo->markJobDiscovered($jobClass, $connection, $queue);
    $queueRepo->markQueueDiscovered($connection, $queue);

    // Record 7 successful completions
    for ($i = 0; $i < 7; $i++) {
        $jobRepo->recordCompletion(
            jobClass: $jobClass,
            connection: $connection,
            queue: $queue,
            durationMs: 100.0,
            memoryMb: 10.0,
            cpuTimeMs: 50.0,
        );
    }

    // Record 3 failures
    for ($i = 0; $i < 3; $i++) {
        $jobRepo->recordFailure(
            jobClass: $jobClass,
            connection: $connection,
            queue: $queue,
            exception: 'Test exception',
            failedAt: time(),
        );
    }

    // Execute
    $action = app(CalculateQueueMetricsAction::class);
    $action->execute($connection, $queue);

    // Assert - Failure rate should be 3/10 = 30.0%
    $metrics = $queueRepo->getLatestMetrics($connection, $queue);
    expect($metrics['failure_rate'])->toBe(30.0);
})->group('redis');

it('command calculates all queues', function () {
    $jobRepo = app(JobMetricsRepository::class);
    $queueRepo = app(QueueMetricsRepository::class);

    // Setup multiple queues
    $queues = [
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'redis', 'queue' => 'high'],
        ['connection' => 'redis', 'queue' => 'low'],
    ];

    foreach ($queues as $q) {
        $queueRepo->markQueueDiscovered($q['connection'], $q['queue']);
        $jobClass = 'App\\Jobs\\TestJob'.ucfirst($q['queue']);
        $jobRepo->markJobDiscovered($jobClass, $q['connection'], $q['queue']);

        // Record some completions
        $jobRepo->recordCompletion(
            jobClass: $jobClass,
            connection: $q['connection'],
            queue: $q['queue'],
            durationMs: 150.0,
            memoryMb: 12.0,
            cpuTimeMs: 60.0,
        );
    }

    // Execute command
    $exitCode = Artisan::call('queue-metrics:calculate');

    expect($exitCode)->toBe(0);

    // Verify all queues have metrics
    foreach ($queues as $q) {
        $metrics = $queueRepo->getLatestMetrics($q['connection'], $q['queue']);
        expect($metrics)->not()->toBeEmpty();
        expect($metrics['avg_duration'])->toBe(150.0);
    }
})->group('redis');

it('command calculates specific queue', function () {
    $connection = 'redis';
    $queue = 'specific';
    $jobClass = 'App\\Jobs\\SpecificJob';

    $jobRepo = app(JobMetricsRepository::class);
    $queueRepo = app(QueueMetricsRepository::class);

    $queueRepo->markQueueDiscovered($connection, $queue);
    $jobRepo->markJobDiscovered($jobClass, $connection, $queue);

    $jobRepo->recordCompletion(
        jobClass: $jobClass,
        connection: $connection,
        queue: $queue,
        durationMs: 250.0,
        memoryMb: 20.0,
        cpuTimeMs: 100.0,
    );

    // Execute command for specific queue
    $exitCode = Artisan::call('queue-metrics:calculate', [
        '--connection' => $connection,
        '--queue' => $queue,
    ]);

    expect($exitCode)->toBe(0);

    $metrics = $queueRepo->getLatestMetrics($connection, $queue);
    expect($metrics['avg_duration'])->toBe(250.0);
})->group('redis');

it('command fails when queue specified without connection', function () {
    $exitCode = Artisan::call('queue-metrics:calculate', [
        '--queue' => 'test',
    ]);

    expect($exitCode)->toBe(1);
})->group('redis');
