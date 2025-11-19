<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\DurationStats;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\FailureInfo;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\JobExecutionData;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\JobMetricsData;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\MemoryStats;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\ThroughputStats;
use PHPeek\LaravelQueueMetrics\Events\MetricsRecorded;

beforeEach(function () {
    Event::fake([MetricsRecorded::class]);
    Carbon::setTestNow('2024-01-15 10:30:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('can be dispatched with job metrics data', function () {
    $metricsData = new JobMetricsData(
        jobClass: 'App\Jobs\ProcessOrder',
        connection: 'redis',
        queue: 'default',
        execution: new JobExecutionData(
            totalProcessed: 95,
            totalFailed: 5,
            successRate: 95.0,
            failureRate: 5.0,
        ),
        duration: new DurationStats(
            avg: 150.5,
            min: 50.0,
            max: 300.0,
            p50: 140.0,
            p95: 280.0,
            p99: 295.0,
            stddev: 45.2,
        ),
        memory: new MemoryStats(
            avg: 25.3,
            peak: 50.0,
            p95: 45.0,
            p99: 48.0,
        ),
        throughput: new ThroughputStats(
            perMinute: 10.0,
            perHour: 600.0,
            perDay: 14400.0,
        ),
        failures: new FailureInfo(
            count: 5,
            rate: 5.0,
            lastFailedAt: null,
            lastException: null,
        ),
        windowStats: [],
        calculatedAt: Carbon::now(),
    );

    MetricsRecorded::dispatch($metricsData);

    Event::assertDispatched(MetricsRecorded::class, function ($event) {
        return $event->metrics->jobClass === 'App\Jobs\ProcessOrder'
            && $event->metrics->connection === 'redis'
            && $event->metrics->queue === 'default'
            && $event->metrics->execution->totalProcessed === 95;
    });
})->group('functional');

it('contains complete job metrics data', function () {
    $metricsData = new JobMetricsData(
        jobClass: 'App\Jobs\TestJob',
        connection: 'redis',
        queue: 'high',
        execution: new JobExecutionData(48, 2, 96.0, 4.0),
        duration: new DurationStats(100.0, 80.0, 120.0, 95.0, 115.0, 118.0, 12.0),
        memory: new MemoryStats(30.0, 45.0, 42.0, 44.0),
        throughput: new ThroughputStats(5.0, 300.0, 7200.0),
        failures: new FailureInfo(2, 4.0, null, null),
        windowStats: [],
        calculatedAt: Carbon::now(),
    );

    $event = new MetricsRecorded($metricsData);

    expect($event->metrics)->toBe($metricsData)
        ->and($event->metrics->jobClass)->toBe('App\Jobs\TestJob')
        ->and($event->metrics->execution->successRate)->toBe(96.0)
        ->and($event->metrics->duration->avg)->toBe(100.0)
        ->and($event->metrics->memory->peak)->toBe(45.0)
        ->and($event->metrics->throughput->perMinute)->toBe(5.0);
})->group('functional');

it('is dispatchable using trait', function () {
    expect(class_uses(MetricsRecorded::class))
        ->toContain('Illuminate\Foundation\Events\Dispatchable');
})->group('functional');
