<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Events\JobMetricsCompleted;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([JobMetricsCompleted::class]);
});

it('can be dispatched with job completion metrics', function () {
    JobMetricsCompleted::dispatch(
        'job-123',
        'App\Jobs\ProcessOrder',
        'redis',
        'default',
        150.5,
        25.3,
        12.4,
        'worker-01',
    );

    Event::assertDispatched(JobMetricsCompleted::class, function ($event) {
        return $event->jobId === 'job-123'
            && $event->jobClass === 'App\Jobs\ProcessOrder'
            && $event->connection === 'redis'
            && $event->queue === 'default'
            && $event->durationMs === 150.5
            && $event->memoryMb === 25.3
            && $event->cpuTimeMs === 12.4
            && $event->hostname === 'worker-01';
    });
})->group('functional');

it('contains all per-job metrics data', function () {
    $event = new JobMetricsCompleted(
        jobId: 'job-456',
        jobClass: 'App\Jobs\SendEmail',
        connection: 'sqs',
        queue: 'emails',
        durationMs: 200.0,
        memoryMb: 32.5,
        cpuTimeMs: 18.7,
        hostname: 'worker-02',
    );

    expect($event->jobId)->toBe('job-456')
        ->and($event->jobClass)->toBe('App\Jobs\SendEmail')
        ->and($event->connection)->toBe('sqs')
        ->and($event->queue)->toBe('emails')
        ->and($event->durationMs)->toBe(200.0)
        ->and($event->memoryMb)->toBe(32.5)
        ->and($event->cpuTimeMs)->toBe(18.7)
        ->and($event->hostname)->toBe('worker-02');
})->group('functional');

it('allows null hostname', function () {
    $event = new JobMetricsCompleted(
        jobId: 'job-789',
        jobClass: 'App\Jobs\TestJob',
        connection: 'redis',
        queue: 'default',
        durationMs: 100.0,
        memoryMb: 10.0,
        cpuTimeMs: 5.0,
    );

    expect($event->hostname)->toBeNull();
})->group('functional');

it('is dispatchable using trait', function () {
    expect(class_uses(JobMetricsCompleted::class))
        ->toContain('Illuminate\Foundation\Events\Dispatchable');
})->group('functional');
