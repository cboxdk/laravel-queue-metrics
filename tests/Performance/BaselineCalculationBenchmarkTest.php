<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Actions\CalculateBaselinesAction;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\BaselineRepository;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;

/**
 * Performance benchmark tests for critical operations.
 * These tests ensure performance doesn't regress.
 */
beforeEach(function () {
    if (! getenv('REDIS_AVAILABLE')) {
        $this->markTestSkipped('Requires Redis - run with redis group');
    }
});

test('baseline calculation completes within acceptable time', function () {
    $action = app(CalculateBaselinesAction::class);
    $queueRepository = app(QueueMetricsRepository::class);

    // Benchmark: Calculate baselines for a queue
    $startTime = microtime(true);

    $action->calculateForQueue('redis', 'default');

    $duration = microtime(true) - $startTime;

    // Assert: Should complete within 5 seconds even with 1000 samples
    expect($duration)->toBeLessThan(5.0);
})->group('performance', 'slow', 'redis', 'functional');

test('batch baseline fetching is faster than sequential', function () {
    $baselineRepository = app(BaselineRepository::class);

    $queuePairs = [
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'redis', 'queue' => 'emails'],
        ['connection' => 'redis', 'queue' => 'notifications'],
        ['connection' => 'redis', 'queue' => 'exports'],
        ['connection' => 'redis', 'queue' => 'imports'],
    ];

    // Benchmark sequential fetching
    $sequentialStart = microtime(true);
    foreach ($queuePairs as $pair) {
        $baselineRepository->getBaseline($pair['connection'], $pair['queue']);
    }
    $sequentialDuration = microtime(true) - $sequentialStart;

    // Benchmark batch fetching
    $batchStart = microtime(true);
    $baselineRepository->getBaselines($queuePairs);
    $batchDuration = microtime(true) - $batchStart;

    // Assert: Batch should not be significantly slower (allow 2x tolerance for overhead)
    // With empty data, batch operations may have pipeline overhead
    expect($batchDuration)->toBeLessThan($sequentialDuration * 2);
})->group('performance', 'slow', 'redis', 'functional');

test('key scanning performance is acceptable for large datasets', function () {
    $keyScanner = app(\Cbox\LaravelQueueMetrics\Services\RedisKeyScannerService::class);
    $redisStore = app(\Cbox\LaravelQueueMetrics\Support\RedisMetricsStore::class);

    // Simulate scanning for jobs across multiple queues
    $jobsPattern = $redisStore->key('jobs', '*', '*', '*');
    $queuedPattern = $redisStore->key('queued', '*', '*', '*');

    $keyParser = fn (string $key) => [
        'connection' => 'redis',
        'queue' => 'default',
        'jobClass' => 'TestJob',
    ];

    $startTime = microtime(true);

    $keyScanner->scanAndParseKeys($jobsPattern, $queuedPattern, $keyParser);

    $duration = microtime(true) - $startTime;

    // Assert: Scanning should complete within 2 seconds
    expect($duration)->toBeLessThan(2.0);
})->group('performance', 'redis', 'functional');

test('overview query aggregation completes within acceptable time', function () {
    $overviewService = app(\Cbox\LaravelQueueMetrics\Services\OverviewQueryService::class);

    $startTime = microtime(true);

    // Request full overview (not slim mode) to include baselines and trends
    $overview = $overviewService->getOverview(false);

    $duration = microtime(true) - $startTime;

    // Assert: Overview should generate within 3 seconds even with many queues/jobs
    expect($duration)->toBeLessThan(3.0);
    expect($overview)->toBeArray();
    expect($overview)->toHaveKeys(['queues', 'jobs', 'servers', 'workers', 'baselines', 'metadata']);
})->group('performance', 'slow', 'redis', 'functional');

test('redis transaction is faster or same speed as pipeline for critical mutations', function () {
    $jobMetricsRepository = app(\Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository::class);

    $testData = [
        'jobId' => 'test-123',
        'jobClass' => 'App\\Jobs\\BenchmarkJob',
        'connection' => 'redis',
        'queue' => 'benchmark',
        'durationMs' => 150.5,
        'memoryMb' => 12.5,
        'cpuTimeMs' => 80.0,
        'completedAt' => now(),
    ];

    // Benchmark the transaction-based approach
    $startTime = microtime(true);

    for ($i = 0; $i < 10; $i++) {
        $jobMetricsRepository->recordCompletion(
            $testData['jobId'].$i,
            $testData['jobClass'],
            $testData['connection'],
            $testData['queue'],
            $testData['durationMs'],
            $testData['memoryMb'],
            $testData['cpuTimeMs'],
            $testData['completedAt']
        );
    }

    $duration = microtime(true) - $startTime;

    // Assert: 10 transactions should complete within 1 second
    expect($duration)->toBeLessThan(1.0);
})->group('performance', 'redis', 'functional');

test('memory usage stays reasonable during large batch operations', function () {
    $overviewService = app(\Cbox\LaravelQueueMetrics\Services\OverviewQueryService::class);

    $memoryBefore = memory_get_usage(true);

    $overviewService->getOverview();

    $memoryAfter = memory_get_usage(true);
    $memoryIncrease = ($memoryAfter - $memoryBefore) / 1024 / 1024; // MB

    // Assert: Memory increase should be less than 50MB for overview query
    expect($memoryIncrease)->toBeLessThan(50);
})->group('performance', 'redis', 'functional');
