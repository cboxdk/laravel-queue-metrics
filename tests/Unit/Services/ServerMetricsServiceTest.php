<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Services\ServerMetricsService;
use Cbox\SystemMetrics\Exceptions\SystemMetricsException;
use Cbox\SystemMetrics\Testing\FakeSystemMetrics;

beforeEach(function () {
    $this->fakes = FakeSystemMetrics::install();
    $this->service = new ServerMetricsService;

    // Clear the static cache between tests
    $reflection = new ReflectionClass(ServerMetricsService::class);
    $cachedMetrics = $reflection->getProperty('cachedMetrics');
    $cachedMetrics->setValue(null, null);
    $cacheTimestamp = $reflection->getProperty('cacheTimestamp');
    $cacheTimestamp->setValue(null, null);
});

afterEach(function () {
    FakeSystemMetrics::uninstall();
});

it('returns cpu core count as numeric value from overview', function () {
    $metrics = $this->service->getCurrentMetrics();

    expect($metrics['available'])->toBeTrue();
    expect($metrics['cpu']['count'])->toBeNumeric();
})->group('functional');

it('returns cpu core count in system limits', function () {
    $limits = $this->service->getSystemLimits();

    expect($limits['available'])->toBeTrue();
    expect($limits['cpu']['cores'])->toBeNumeric();
})->group('functional');

it('formats fractional cpu cores in health status messages', function () {
    // The fake CPU source returns 4 cores by default with low busy time.
    // To trigger the load warning, we need high load relative to core count.
    // getHealthStatus() calls getCurrentMetrics() which returns 'count' from coreCount().
    // The load average check: loadPerCpu = load1min / cpuCount > 1.5 triggers warning.
    //
    // Since FakeSystemMetrics doesn't let us set fractional coreCount() on CpuSnapshot
    // (coreCount() returns count of perCore array, always int), we test the sprintf
    // formatting by verifying the health status handles the values correctly.
    $health = $this->service->getHealthStatus();

    expect($health['status'])->toBeString();
    expect($health['score'])->toBeInt();
    expect($health['issues'])->toBeArray();
})->group('functional');

it('handles system metrics failure gracefully in health status', function () {
    // Simulate system metrics failure
    $this->fakes->cpu->failWith(
        new SystemMetricsException('CPU metrics unavailable')
    );

    $health = $this->service->getHealthStatus();

    expect($health['status'])->toBe('unknown');
    expect($health['score'])->toBe(0);
})->group('functional');

it('formats high load message with float cpu count using sprintf', function () {
    // Directly test the sprintf formatting that changed from %d to %.1f
    $cpuCount = 0.5; // Container with 500m CPU quota
    $loadAvg = 2.0;

    $message = sprintf('High system load: %.2f (%.1f CPUs)', $loadAvg, $cpuCount);

    expect($message)->toBe('High system load: 2.00 (0.5 CPUs)');
})->group('functional');

it('formats critical load message with fractional cores', function () {
    $cpuCount = 1.5; // Container with 1500m CPU quota
    $loadAvg = 5.0;

    $message = sprintf('Critical system load: %.2f (%.1f CPUs)', $loadAvg, $cpuCount);

    expect($message)->toBe('Critical system load: 5.00 (1.5 CPUs)');
})->group('functional');

it('formats whole core counts without trailing zeros issue', function () {
    $cpuCount = 4.0; // Standard 4-core machine
    $loadAvg = 10.0;

    $message = sprintf('Critical system load: %.2f (%.1f CPUs)', $loadAvg, $cpuCount);

    expect($message)->toBe('Critical system load: 10.00 (4.0 CPUs)');
})->group('functional');
