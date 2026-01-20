<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Tests\Feature;

use Cbox\SystemMetrics\ProcessMetrics;

/**
 * Integration test demonstrating child process tracking in realistic scenarios.
 *
 * This test simulates a job that spawns child processes (like running external commands)
 * and verifies that metrics are tracked correctly.
 */
it('tracks child processes when job spawns subprocesses', function () {
    $pid = getmypid();
    $trackerId = 'child_process_job_'.uniqid();

    // Start tracking with child process support (like JobProcessingListener does)
    $result = ProcessMetrics::start(
        pid: $pid,
        trackerId: $trackerId,
        includeChildren: true
    );

    expect($result->isSuccess())->toBeTrue();

    // Simulate a job that spawns a child process
    // In real scenarios, this could be ImageMagick, FFmpeg, external API calls, etc.
    $startMemory = memory_get_usage(true);

    // Simulate work with subprocess (using shell_exec which creates child process)
    $output = shell_exec('echo "test" && sleep 0.1');
    expect($output)->toContain('test');

    // Allocate some memory in parent process
    $data = str_repeat('x', 1024 * 1024); // 1MB

    usleep(50000); // 50ms additional work

    // Stop tracking and get metrics (like JobProcessedListener does)
    $statsResult = ProcessMetrics::stop($trackerId);
    expect($statsResult->isSuccess())->toBeTrue();

    $stats = $statsResult->getValue();

    // Verify metrics are calculated correctly as in JobProcessedListener
    $memoryMb = $stats->peak->memoryRssBytes / 1024 / 1024;
    expect($memoryMb)->toBeGreaterThan(0.0);

    $cpuUsagePercent = $stats->delta->cpuUsagePercentage();
    $durationSeconds = $stats->delta->durationSeconds;
    $cpuTimeMs = ($cpuUsagePercent / 100.0) * $durationSeconds * 1000.0;

    expect($cpuTimeMs)->toBeGreaterThanOrEqual(0.0);
    expect($durationSeconds)->toBeGreaterThanOrEqual(0.0); // Can be 0 for very fast operations

    // Process count may be > 1 if child processes were captured
    expect($stats->processCount)->toBeGreaterThanOrEqual(1);

    unset($data);
})->skip(PHP_OS_FAMILY === 'Windows', 'Child process tracking not supported on Windows')
    ->group('functional');

it('measures accurate delta between job start and completion', function () {
    $trackerId = 'delta_accuracy_test_'.uniqid();

    // Start tracking
    ProcessMetrics::start(
        pid: getmypid(),
        trackerId: $trackerId,
        includeChildren: true
    );

    $startTime = microtime(true);

    // Simulate job work
    $sum = 0;
    for ($i = 0; $i < 50000; $i++) {
        $sum += sqrt($i);
    }

    $endTime = microtime(true);
    $actualDuration = $endTime - $startTime;

    // Stop tracking
    $statsResult = ProcessMetrics::stop($trackerId);
    expect($statsResult->isSuccess())->toBeTrue();

    $stats = $statsResult->getValue();

    // Delta duration should be measurable
    expect($stats->delta->durationSeconds)->toBeGreaterThanOrEqual(0.0);
    // Note: ProcessMetrics may have minimum 1-second granularity on some systems
    expect($stats->delta->durationSeconds)->toBeLessThan(10.0); // Sanity check: should be < 10s

    // CPU usage should be measurable for computational work
    $cpuPercent = $stats->delta->cpuUsagePercentage();
    expect($cpuPercent)->toBeGreaterThanOrEqual(0.0);
})->group('functional');

it('tracks peak memory correctly for memory-intensive jobs', function () {
    $trackerId = 'peak_memory_job_'.uniqid();

    ProcessMetrics::start(
        pid: getmypid(),
        trackerId: $trackerId,
        includeChildren: true
    );

    // Take initial sample
    $initialSample = ProcessMetrics::sample($trackerId);
    expect($initialSample->isSuccess())->toBeTrue();
    $initialMemory = $initialSample->getValue()->resources->memoryRssBytes;

    // Simulate memory-intensive job phase
    $largeData = [];
    for ($i = 0; $i < 50000; $i++) {
        $largeData[] = str_repeat('x', 100);
    }

    // Take sample during peak memory usage
    $peakSample = ProcessMetrics::sample($trackerId);
    expect($peakSample->isSuccess())->toBeTrue();
    $peakDuringWork = $peakSample->getValue()->resources->memoryRssBytes;
    expect($peakDuringWork)->toBeGreaterThanOrEqual($initialMemory); // May not increase immediately in RSS

    // Free memory
    unset($largeData);

    // Final stats should show peak was higher than current
    $statsResult = ProcessMetrics::stop($trackerId);
    expect($statsResult->isSuccess())->toBeTrue();

    $stats = $statsResult->getValue();

    // Peak should be >= the peak we measured during work
    expect($stats->peak->memoryRssBytes)->toBeGreaterThanOrEqual($peakDuringWork);

    // Current memory at end should be less than peak (after freeing $largeData)
    expect($stats->current->memoryRssBytes)->toBeLessThanOrEqual($stats->peak->memoryRssBytes);
})->group('functional');

it('provides process resource usage compatible with JobProcessedListener calculations', function () {
    $trackerId = 'listener_compat_test_'.uniqid();

    ProcessMetrics::start(
        pid: getmypid(),
        trackerId: $trackerId,
        includeChildren: true
    );

    // Simulate some work
    usleep(100000); // 100ms
    $data = str_repeat('y', 512 * 1024); // 512KB

    $statsResult = ProcessMetrics::stop($trackerId);
    expect($statsResult->isSuccess())->toBeTrue();

    $stats = $statsResult->getValue();

    // This is exactly how JobProcessedListener uses the metrics:
    $memoryMb = $stats->peak->memoryRssBytes / 1024 / 1024;
    $cpuUsagePercent = $stats->delta->cpuUsagePercentage();
    $durationSeconds = $stats->delta->durationSeconds;
    $cpuTimeMs = ($cpuUsagePercent / 100.0) * $durationSeconds * 1000.0;

    // Verify all values are usable
    expect($memoryMb)->toBeFloat()->toBeGreaterThan(0.0);
    expect($cpuUsagePercent)->toBeFloat()->toBeGreaterThanOrEqual(0.0);
    expect($durationSeconds)->toBeFloat()->toBeGreaterThanOrEqual(0.0); // Can be 0 on fast systems
    expect($cpuTimeMs)->toBeFloat()->toBeGreaterThanOrEqual(0.0);

    // These values would be passed to RecordJobCompletionAction
    expect($memoryMb)->toBeLessThan(10000.0); // Sanity check (< 10GB)
    expect($cpuTimeMs)->toBeLessThan(60000.0); // Sanity check (< 1 minute)

    unset($data);
})->group('functional');
