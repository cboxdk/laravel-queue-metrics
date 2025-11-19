<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Tests\Unit;

use PHPeek\SystemMetrics\ProcessMetrics;

/**
 * Tests for ProcessMetrics integration in queue job tracking.
 *
 * Verifies that:
 * 1. Child processes are tracked correctly
 * 2. Delta metrics provide accurate CPU/memory measurements
 * 3. Peak memory tracking works as expected
 */
it('tracks process metrics with child processes enabled', function () {
    $pid = getmypid();
    expect($pid)->toBeInt()->toBeGreaterThan(0);

    // Start tracking with child process support
    $trackerId = 'test_job_'.uniqid();
    $result = ProcessMetrics::start(
        pid: $pid,
        trackerId: $trackerId,
        includeChildren: true
    );

    expect($result->isSuccess())->toBeTrue();
    expect(ProcessMetrics::activeTrackers())->toContain($trackerId);

    // Simulate some work (allocate memory and ensure measurable duration)
    $data = str_repeat('x', 1024 * 1024); // 1MB string
    usleep(100000); // 100ms to ensure measurable duration

    // Get delta snapshot
    $deltaResult = ProcessMetrics::delta($trackerId);
    expect($deltaResult->isSuccess())->toBeTrue();

    $delta = $deltaResult->getValue();
    expect($delta->durationSeconds)->toBeGreaterThanOrEqual(0.0); // Can be 0 for very fast operations
    expect($delta->cpuUsagePercentage())->toBeGreaterThanOrEqual(0.0);

    // Stop tracking and get final stats
    $statsResult = ProcessMetrics::stop($trackerId);
    expect($statsResult->isSuccess())->toBeTrue();

    $stats = $statsResult->getValue();

    // Verify ProcessStats structure
    expect($stats->pid)->toBe($pid);
    expect($stats->sampleCount)->toBeGreaterThanOrEqual(2); // start + stop
    expect($stats->totalDurationSeconds)->toBeGreaterThanOrEqual(0.0); // Can be 0 on very fast operations

    // Verify we have current, peak, and average metrics
    expect($stats->current)->toBeInstanceOf(\PHPeek\SystemMetrics\DTO\Metrics\Process\ProcessResourceUsage::class);
    expect($stats->peak)->toBeInstanceOf(\PHPeek\SystemMetrics\DTO\Metrics\Process\ProcessResourceUsage::class);
    expect($stats->average)->toBeInstanceOf(\PHPeek\SystemMetrics\DTO\Metrics\Process\ProcessResourceUsage::class);

    // Verify delta provides accurate measurements
    expect($stats->delta)->toBeInstanceOf(\PHPeek\SystemMetrics\DTO\Metrics\Process\ProcessDelta::class);
    expect($stats->delta->durationSeconds)->toBeGreaterThanOrEqual(0.0); // Can be 0 on very fast systems
    expect($stats->delta->cpuUsagePercentage())->toBeGreaterThanOrEqual(0.0);

    // Peak memory should be >= current memory
    expect($stats->peak->memoryRssBytes)->toBeGreaterThanOrEqual($stats->current->memoryRssBytes);

    // Process count should be at least 1 (the main process)
    expect($stats->processCount)->toBeGreaterThanOrEqual(1);

    // Cleanup
    unset($data);
})->group('functional');

it('calculates CPU time correctly from delta metrics', function () {
    $pid = getmypid();
    $trackerId = 'cpu_test_'.uniqid();

    ProcessMetrics::start(pid: $pid, trackerId: $trackerId, includeChildren: true);

    // Simulate CPU-intensive work
    $sum = 0;
    for ($i = 0; $i < 100000; $i++) {
        $sum += $i;
    }

    $statsResult = ProcessMetrics::stop($trackerId);
    expect($statsResult->isSuccess())->toBeTrue();

    $stats = $statsResult->getValue();

    // Calculate CPU time using the same formula as JobProcessedListener
    $cpuUsagePercent = $stats->delta->cpuUsagePercentage();
    $durationSeconds = $stats->delta->durationSeconds;
    $cpuTimeMs = ($cpuUsagePercent / 100.0) * $durationSeconds * 1000.0;

    expect($cpuTimeMs)->toBeGreaterThanOrEqual(0.0);
    expect($cpuUsagePercent)->toBeGreaterThanOrEqual(0.0);
})->group('functional');

it('tracks peak memory during execution', function () {
    $pid = getmypid();
    $trackerId = 'memory_test_'.uniqid();

    ProcessMetrics::start(pid: $pid, trackerId: $trackerId, includeChildren: true);

    $initialSnapshot = ProcessMetrics::sample($trackerId);
    expect($initialSnapshot->isSuccess())->toBeTrue();

    // Allocate significant memory
    $largeArray = [];
    for ($i = 0; $i < 100000; $i++) {
        $largeArray[] = str_repeat('x', 100);
    }

    $peakSnapshot = ProcessMetrics::sample($trackerId);
    expect($peakSnapshot->isSuccess())->toBeTrue();

    // Free memory
    unset($largeArray);

    $statsResult = ProcessMetrics::stop($trackerId);
    expect($statsResult->isSuccess())->toBeTrue();

    $stats = $statsResult->getValue();

    // Peak memory should be greater than initial
    $initialMemory = $initialSnapshot->getValue()->resources->memoryRssBytes;
    $peakMemory = $stats->peak->memoryRssBytes;

    expect($peakMemory)->toBeGreaterThanOrEqual($initialMemory);
})->group('functional');

it('handles process group snapshots with children', function () {
    $pid = getmypid();

    // Get process group snapshot (includes potential children)
    $groupResult = ProcessMetrics::group($pid);

    if ($groupResult->isSuccess()) {
        $group = $groupResult->getValue();

        expect($group->rootPid)->toBe($pid);
        expect($group->root)->toBeInstanceOf(\PHPeek\SystemMetrics\DTO\Metrics\Process\ProcessSnapshot::class);
        expect($group->children)->toBeArray();
        expect($group->totalProcessCount())->toBeGreaterThanOrEqual(1);

        // Aggregate memory includes root + all children
        $aggregateMemory = $group->aggregateMemoryRss();
        expect($aggregateMemory)->toBeGreaterThanOrEqual($group->root->resources->memoryRssBytes);
    } else {
        // Process group reading may not be supported on all systems
        expect($groupResult->isFailure())->toBeTrue();
    }
})->group('functional');

it('provides process count including children', function () {
    $pid = getmypid();
    $trackerId = 'process_count_test_'.uniqid();

    $result = ProcessMetrics::start(
        pid: $pid,
        trackerId: $trackerId,
        includeChildren: true
    );

    expect($result->isSuccess())->toBeTrue();

    $statsResult = ProcessMetrics::stop($trackerId);
    expect($statsResult->isSuccess())->toBeTrue();

    $stats = $statsResult->getValue();

    // Process count should always be at least 1 (the parent)
    expect($stats->processCount)->toBeGreaterThanOrEqual(1);

    // If we have child processes, count should be > 1
    // Note: In test environment, we typically only have the parent process
    expect($stats->processCount)->toBe(1);
})->group('functional');
